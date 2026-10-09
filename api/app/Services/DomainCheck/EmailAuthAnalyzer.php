<?php

namespace App\Services\DomainCheck;

use App\Services\DomainCheck\Dns\DnsLookup;

/**
 * Parses SPF and DMARC TXT records into a status, the raw value and warnings.
 */
final class EmailAuthAnalyzer
{
    private const SPF_LOOKUP_MECHANISMS = ['include', 'a', 'mx', 'ptr', 'exists', 'redirect'];

    private const SPF_ALL_QUALIFIERS = [
        '-' => 'fail',
        '~' => 'softfail',
        '?' => 'neutral',
        '+' => 'pass',
        '' => 'pass',
    ];

    /**
     * @param  DnsLookup  $txt  TXT lookup of the domain itself.
     * @return array{status: 'found'|'missing'|'multiple'|'error', value: string|null, all: string|null, lookups: int, includes: list<string>, warnings: list<string>, error: string|null}
     */
    public static function spf(DnsLookup $txt): array
    {
        $result = ['status' => 'missing', 'value' => null, 'all' => null, 'lookups' => 0, 'includes' => [], 'warnings' => [], 'error' => null];

        if ($txt->hasFailed()) {
            return [...$result, 'status' => 'error', 'error' => $txt->errorMessage()];
        }

        $records = array_values(array_filter(
            $txt->isOk() ? $txt->records : [],
            fn (string $record): bool => preg_match('/^v=spf1(\s|$)/i', trim($record)) === 1,
        ));

        if ($records === []) {
            return $result;
        }

        $value = trim($records[0]);
        $warnings = [];
        $includes = [];
        $lookups = 0;
        $all = null;
        $hasRedirect = false;

        foreach (preg_split('/\s+/', $value) as $term) {
            $qualifier = in_array($term[0] ?? '', ['+', '-', '~', '?'], true) ? $term[0] : '';
            $mechanism = strtolower(substr($term, strlen($qualifier)));
            $name = preg_split('/[:=\/]/', $mechanism)[0];

            if (in_array($name, self::SPF_LOOKUP_MECHANISMS, true)) {
                $lookups++;
            }

            if ($name === 'include') {
                $includes[] = substr($mechanism, strlen('include:'));
            }

            if ($name === 'redirect') {
                $hasRedirect = true;
                $includes[] = substr($mechanism, strlen('redirect='));
            }

            if ($name === 'ptr') {
                $warnings[] = 'The "ptr" mechanism is deprecated (RFC 7208).';
            }

            if ($mechanism === 'all') {
                $all = $qualifier.'all ('.self::SPF_ALL_QUALIFIERS[$qualifier].')';

                if ($qualifier === '+' || $qualifier === '') {
                    $warnings[] = '"+all" authorizes any server on the internet to send as this domain.';
                }
            }
        }

        if ($all === null && ! $hasRedirect) {
            $warnings[] = 'No "all" mechanism: unlisted senders are treated as neutral.';
        }

        if ($lookups > 10) {
            $warnings[] = "SPF uses {$lookups} DNS-lookup mechanisms at the top level (limit is 10).";
        }

        if (count($records) > 1) {
            $warnings[] = 'Multiple SPF records found: receivers will return a permerror.';
        }

        return [
            'status' => count($records) > 1 ? 'multiple' : 'found',
            'value' => $value,
            'all' => $all,
            'lookups' => $lookups,
            'includes' => $includes,
            'warnings' => $warnings,
            'error' => null,
        ];
    }

    /**
     * @param  DnsLookup  $txt  TXT lookup of _dmarc.<domain>.
     * @return array{status: 'found'|'missing'|'multiple'|'invalid'|'error', value: string|null, policy: string|null, subdomain_policy: string|null, pct: int|null, rua: string|null, ruf: string|null, warnings: list<string>, error: string|null}
     */
    public static function dmarc(DnsLookup $txt): array
    {
        $result = ['status' => 'missing', 'value' => null, 'policy' => null, 'subdomain_policy' => null, 'pct' => null, 'rua' => null, 'ruf' => null, 'warnings' => [], 'error' => null];

        if ($txt->hasFailed()) {
            return [...$result, 'status' => 'error', 'error' => $txt->errorMessage()];
        }

        $records = array_values(array_filter(
            $txt->isOk() ? $txt->records : [],
            fn (string $record): bool => preg_match('/^v\s*=\s*DMARC1\s*(;|$)/i', trim($record)) === 1,
        ));

        if ($records === []) {
            return $result;
        }

        $value = trim($records[0]);
        $tags = [];

        foreach (explode(';', $value) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $tagValue] = array_map('trim', explode('=', $pair, 2));
                $tags[strtolower($key)] = $tagValue;
            }
        }

        $policy = isset($tags['p']) ? strtolower($tags['p']) : null;
        $warnings = [];

        if (! in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $warnings[] = 'Missing or invalid "p" tag.';
        } elseif ($policy === 'none') {
            $warnings[] = 'Policy is "none": monitoring only, spoofed mail is not blocked.';
        }

        if (! isset($tags['rua'])) {
            $warnings[] = 'No aggregate report address (rua).';
        }

        if (isset($tags['pct']) && (int) $tags['pct'] < 100) {
            $warnings[] = "Policy applies to only {$tags['pct']}% of messages.";
        }

        if (count($records) > 1) {
            $warnings[] = 'Multiple DMARC records found: receivers will ignore DMARC.';
        }

        return [
            'status' => match (true) {
                count($records) > 1 => 'multiple',
                ! in_array($policy, ['none', 'quarantine', 'reject'], true) => 'invalid',
                default => 'found',
            },
            'value' => $value,
            'policy' => $policy,
            'subdomain_policy' => isset($tags['sp']) ? strtolower($tags['sp']) : null,
            'pct' => isset($tags['pct']) ? (int) $tags['pct'] : null,
            'rua' => $tags['rua'] ?? null,
            'ruf' => $tags['ruf'] ?? null,
            'warnings' => $warnings,
            'error' => null,
        ];
    }
}
