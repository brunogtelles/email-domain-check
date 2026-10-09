<?php

namespace App\Services\DomainCheck;

/**
 * Extracts domains from an uploaded CSV / TXT file.
 *
 * Accepts one value per line or CSV (comma, semicolon or tab separated). When a
 * header row names a domain/email column it is used, otherwise the first cell
 * that looks like a domain or e-mail is taken. An optional "selector" column
 * provides a DKIM selector. Duplicate domains are removed.
 */
final class BulkInputParser
{
    private const DOMAIN_HEADERS = ['domain', 'domains', 'domain name', 'email', 'emails', 'e-mail', 'email address', 'website', 'url', 'host', 'hostname'];

    private const SELECTOR_HEADERS = ['selector', 'dkim selector', 'dkim_selector'];

    /**
     * @return array{entries: list<array{input: string, domain: string|null, selector: string|null}>, duplicates: int}
     */
    public function parse(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contents),
            fn (string $line): bool => trim($line, " \t,;\"'") !== '',
        ));

        if ($lines === []) {
            return ['entries' => [], 'duplicates' => 0];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $header = array_map(fn (string $cell): string => strtolower(trim($cell)), $this->cells($lines[0], $delimiter));
        $domainColumn = $this->findColumn($header, self::DOMAIN_HEADERS);
        $selectorColumn = $this->findColumn($header, self::SELECTOR_HEADERS);

        if ($domainColumn !== null) {
            array_shift($lines);
        }

        $entries = [];
        $seen = [];
        $duplicates = 0;

        foreach ($lines as $line) {
            $cells = $this->cells($line, $delimiter);
            [$input, $domain] = $this->pickDomain($cells, $domainColumn);

            if ($input === '') {
                continue;
            }

            $selector = $selectorColumn !== null ? trim($cells[$selectorColumn] ?? '') : '';
            $selector = preg_match('/^[a-z0-9._-]{1,100}$/i', $selector) === 1 ? strtolower($selector) : null;

            if ($domain !== null) {
                $key = $domain.'|'.$selector;

                if (isset($seen[$key])) {
                    $duplicates++;

                    continue;
                }

                $seen[$key] = true;
            }

            $entries[] = ['input' => mb_substr($input, 0, 320), 'domain' => $domain, 'selector' => $selector];
        }

        return ['entries' => $entries, 'duplicates' => $duplicates];
    }

    /**
     * @param  list<string>  $cells
     * @return array{0: string, 1: string|null}
     */
    private function pickDomain(array $cells, ?int $domainColumn): array
    {
        if ($domainColumn !== null) {
            $input = trim($cells[$domainColumn] ?? '');

            return [$input, $input === '' ? null : DomainNormalizer::normalize($input)];
        }

        foreach ($cells as $cell) {
            $domain = DomainNormalizer::normalize($cell);

            if ($domain !== null) {
                return [trim($cell), $domain];
            }
        }

        $firstNonEmpty = collect($cells)->map(fn (string $cell): string => trim($cell))->first(fn (string $cell): bool => $cell !== '');

        return [(string) $firstNonEmpty, null];
    }

    private function detectDelimiter(string $line): ?string
    {
        $counts = array_filter([',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")]);

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @return list<string>
     */
    private function cells(string $line, ?string $delimiter): array
    {
        if ($delimiter === null) {
            return [$line];
        }

        return array_map(fn (?string $cell): string => (string) $cell, str_getcsv($line, $delimiter, '"', '\\'));
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $names
     */
    private function findColumn(array $header, array $names): ?int
    {
        foreach ($header as $index => $cell) {
            if (in_array($cell, $names, true)) {
                return $index;
            }
        }

        return null;
    }
}
