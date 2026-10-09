<?php

namespace App\Services\DomainCheck;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\DnsLookup;
use App\Services\DomainCheck\Dns\Resolver;
use Illuminate\Container\Attributes\Config;
use React\Dns\Model\Message;

/**
 * Detects whether a domain uses Google Workspace, Microsoft 365, another provider or none.
 *
 * The primary (lowest priority) MX host decides. SPF includes, verification TXT
 * records, DKIM selectors and autodiscover are collected as supporting evidence
 * and used to identify the provider behind a security gateway (Proofpoint, Mimecast...).
 *
 * @phpstan-type Evidence array{signal: string, provider: string, detail: string, weight: int}
 */
final class ProviderDetector
{
    /** Minimum evidence score to attribute a domain behind a security gateway. */
    private const GATEWAY_THRESHOLD = 3;

    /** Minimum evidence score when the MX host is unknown (needs two strong signals). */
    private const UNKNOWN_MX_THRESHOLD = 5;

    /**
     * @param  array<string, array{label: string, mx: list<string>, spf: list<string>, txt: list<string>, dkim_txt: list<string>, dkim_cname: array<string, string>, autodiscover: list<string>}>  $providers
     * @param  array<string, list<string>>  $otherProviders
     * @param  array<string, list<string>>  $gateways
     */
    public function __construct(
        private readonly Resolver $resolver,
        #[Config('domaincheck.providers')] private readonly array $providers = [],
        #[Config('domaincheck.other_providers')] private readonly array $otherProviders = [],
        #[Config('domaincheck.gateways')] private readonly array $gateways = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function detect(string $domain): array
    {
        $txtSelectors = array_values(array_unique(array_merge(...array_values(array_column($this->providers, 'dkim_txt')))));
        $cnameSelectors = array_values(array_unique(array_merge(...array_map('array_keys', array_values(array_column($this->providers, 'dkim_cname'))))));

        /** @var array{mx: DnsLookup, txt: DnsLookup, autodiscover: DnsLookup, dkim_txt: array<string, DnsLookup>, dkim_cname: array<string, DnsLookup>} $dns */
        $dns = Concurrently::run([
            'mx' => $this->resolver->lookup($domain, Message::TYPE_MX),
            'txt' => $this->resolver->lookup($domain, Message::TYPE_TXT),
            'autodiscover' => $this->resolver->lookup("autodiscover.{$domain}", Message::TYPE_CNAME),
            'dkim_txt' => fn (): array => $this->lookupSelectors($domain, $txtSelectors, Message::TYPE_TXT),
            'dkim_cname' => fn (): array => $this->lookupSelectors($domain, $cnameSelectors, Message::TYPE_CNAME),
        ]);

        $mx = $this->classifyMx($dns['mx'], $domain);
        $evidence = $this->collectEvidence($dns);
        $errors = collect([$dns['mx'], $dns['txt'], $dns['autodiscover'], ...array_values($dns['dkim_txt']), ...array_values($dns['dkim_cname'])])
            ->map(fn (DnsLookup $lookup): ?string => $lookup->errorMessage())
            ->filter()
            ->values()
            ->all();

        $result = [
            'domain' => $domain,
            'mx' => $mx,
            'spf' => EmailAuthAnalyzer::spf($dns['txt'])['value'],
            'errors' => $errors,
            'failure' => null,
        ];

        if ($dns['mx']->hasFailed()) {
            return [...$result, ...$this->verdict('none', null, null, 'MX lookup failed, provider could not be determined.', $evidence), 'failure' => 'MX lookup failed: '.$dns['mx']->error];
        }

        if ($dns['mx']->status === DnsStatus::NxDomain) {
            return [...$result, ...$this->verdict('none', null, null, 'Domain does not exist (NXDOMAIN).', $evidence), 'failure' => 'Domain does not exist (NXDOMAIN)'];
        }

        if ($mx === []) {
            return [...$result, ...$this->verdict('none', null, null, 'No MX records: the domain does not receive email.'.$this->hint($evidence), $evidence)];
        }

        if (count($mx) === 1 && $mx[0]['host'] === '.') {
            return [...$result, ...$this->verdict('none', null, null, 'Null MX (RFC 7505): the domain explicitly does not accept email.', $evidence)];
        }

        $primary = $mx[0];

        foreach ($mx as $record) {
            if (isset($this->providers[$record['kind']])) {
                array_unshift($evidence, [
                    'signal' => 'mx',
                    'provider' => $record['kind'],
                    'detail' => "MX {$record['priority']} {$record['host']}",
                    'weight' => 10,
                ]);
            }
        }

        if (isset($this->providers[$primary['kind']])) {
            $label = $this->providers[$primary['kind']]['label'];

            return [...$result, ...$this->verdict($primary['kind'], $label, 'high', "Primary MX {$primary['host']} belongs to {$label}.", $evidence)];
        }

        if ($primary['kind'] === 'other') {
            $note = $this->strongestProvider($evidence, self::GATEWAY_THRESHOLD) !== null
                ? ' Google/Microsoft signals were also found (possible migration or hybrid setup).'
                : '';

            return [...$result, ...$this->verdict('other', $primary['name'], 'high', "Primary MX {$primary['host']} belongs to {$primary['name']}.{$note}", $evidence)];
        }

        $isGateway = $primary['kind'] === 'gateway';
        $behind = $this->strongestProvider($evidence, $isGateway ? self::GATEWAY_THRESHOLD : self::UNKNOWN_MX_THRESHOLD);

        if ($behind !== null) {
            $label = $this->providers[$behind]['label'];
            $signals = collect($evidence)->where('provider', $behind)->pluck('signal')->unique()->map(fn (string $signal): string => strtoupper($signal))->implode(', ');

            return [...$result, ...$this->verdict(
                $behind,
                $isGateway ? "{$label} (via {$primary['name']})" : $label,
                $isGateway ? 'medium' : 'low',
                $isGateway
                    ? "MX points to the {$primary['name']} security gateway; {$label} detected from {$signals}."
                    : "MX {$primary['host']} is not a known provider, but {$label} was detected from {$signals}.",
                $evidence,
            )];
        }

        return [...$result, ...$this->verdict(
            'other',
            $primary['name'],
            $isGateway ? 'medium' : 'low',
            $isGateway
                ? "MX points to the {$primary['name']} security gateway; the mailbox provider behind it could not be identified."
                : "MX {$primary['host']} does not match a known provider.",
            $evidence,
        )];
    }

    /**
     * @param  list<string>  $selectors
     * @return array<string, DnsLookup>
     */
    private function lookupSelectors(string $domain, array $selectors, int $type): array
    {
        return Concurrently::run(array_combine($selectors, array_map(
            fn (string $selector) => $this->resolver->lookup("{$selector}._domainkey.{$domain}", $type),
            $selectors,
        )));
    }

    /**
     * @return list<array{priority: int, host: string, kind: string, name: string|null}>
     */
    private function classifyMx(DnsLookup $lookup, string $domain): array
    {
        if (! $lookup->isOk()) {
            return [];
        }

        $records = array_map(function (array $record) use ($domain): array {
            $host = rtrim(strtolower($record['target']), '.');

            return ['priority' => (int) $record['priority'], 'host' => $host === '' ? '.' : $host, ...$this->classifyHost($host, $domain)];
        }, $lookup->records);

        usort($records, fn (array $a, array $b): int => [$a['priority'], $a['host']] <=> [$b['priority'], $b['host']]);

        return $records;
    }

    /**
     * @return array{kind: string, name: string|null}
     */
    private function classifyHost(string $host, string $domain): array
    {
        foreach ($this->providers as $key => $provider) {
            if (self::matchesSuffix($host, $provider['mx'])) {
                return ['kind' => $key, 'name' => $provider['label']];
            }
        }

        foreach ($this->gateways as $name => $suffixes) {
            if (self::matchesSuffix($host, $suffixes)) {
                return ['kind' => 'gateway', 'name' => $name];
            }
        }

        foreach ($this->otherProviders as $name => $suffixes) {
            if (self::matchesSuffix($host, $suffixes)) {
                return ['kind' => 'other', 'name' => $name];
            }
        }

        if ($host === '' || $host === '.') {
            return ['kind' => 'none', 'name' => null];
        }

        return [
            'kind' => 'unknown',
            'name' => self::matchesSuffix($host, [$domain]) ? 'Self-hosted' : 'Unknown provider',
        ];
    }

    /**
     * @param  array{txt: DnsLookup, autodiscover: DnsLookup, dkim_txt: array<string, DnsLookup>, dkim_cname: array<string, DnsLookup>}  $dns
     * @return list<Evidence>
     */
    private function collectEvidence(array $dns): array
    {
        $evidence = [];
        $includes = EmailAuthAnalyzer::spf($dns['txt'])['includes'];
        $txtRecords = $dns['txt']->isOk() ? $dns['txt']->records : [];
        $autodiscover = $dns['autodiscover']->isOk() ? rtrim(strtolower($dns['autodiscover']->records[0]), '.') : null;

        foreach ($this->providers as $key => $provider) {
            foreach ($includes as $include) {
                if (self::matchesSuffix(strtolower($include), $provider['spf'])) {
                    $evidence[] = ['signal' => 'spf', 'provider' => $key, 'detail' => "SPF includes {$include}", 'weight' => 3];
                }
            }

            foreach ($txtRecords as $record) {
                foreach ($provider['txt'] as $prefix) {
                    if (str_starts_with(strtolower($record), $prefix)) {
                        $evidence[] = ['signal' => 'txt', 'provider' => $key, 'detail' => 'Verification TXT: '.str($record)->limit(60), 'weight' => $key === 'google' ? 1 : 2];
                    }
                }
            }

            foreach ($provider['dkim_txt'] as $selector) {
                $lookup = $dns['dkim_txt'][$selector] ?? null;

                if ($lookup?->isOk() && collect($lookup->records)->contains(fn (string $record): bool => stripos($record, 'p=') !== false)) {
                    $evidence[] = ['signal' => 'dkim', 'provider' => $key, 'detail' => "DKIM key published at {$selector}._domainkey", 'weight' => 3];
                }
            }

            foreach ($provider['dkim_cname'] as $selector => $suffix) {
                $lookup = $dns['dkim_cname'][$selector] ?? null;
                $target = $lookup?->isOk() ? rtrim(strtolower($lookup->records[0]), '.') : null;

                if ($target !== null && self::matchesSuffix($target, [$suffix])) {
                    $evidence[] = ['signal' => 'dkim', 'provider' => $key, 'detail' => "{$selector}._domainkey CNAME {$target}", 'weight' => 3];
                }
            }

            if ($autodiscover !== null && self::matchesSuffix($autodiscover, $provider['autodiscover'])) {
                $evidence[] = ['signal' => 'autodiscover', 'provider' => $key, 'detail' => "autodiscover CNAME {$autodiscover}", 'weight' => 3];
            }
        }

        return $evidence;
    }

    /**
     * Each signal type counts once per provider, so several verification TXT
     * records do not add up to a strong signal.
     *
     * @param  list<Evidence>  $evidence
     */
    private function strongestProvider(array $evidence, int $threshold): ?string
    {
        $scores = collect($evidence)
            ->where('signal', '!=', 'mx')
            ->groupBy('provider')
            ->map(fn ($items): int => $items->groupBy('signal')->sum(fn ($signals): int => $signals->max('weight')))
            ->sortDesc();

        return $scores->isNotEmpty() && $scores->first() >= $threshold ? (string) $scores->keys()->first() : null;
    }

    /**
     * @param  list<Evidence>  $evidence
     */
    private function hint(array $evidence): string
    {
        $provider = $this->strongestProvider($evidence, self::GATEWAY_THRESHOLD);

        return $provider === null ? '' : " {$this->providers[$provider]['label']} signals exist (SPF/TXT/DKIM) but without MX records mail cannot be delivered.";
    }

    /**
     * @param  list<Evidence>  $evidence
     * @return array{provider: string, provider_label: string, provider_name: string|null, status: string, confidence: string|null, reason: string, evidence: list<Evidence>}
     */
    private function verdict(string $provider, ?string $name, ?string $confidence, string $reason, array $evidence): array
    {
        return [
            'provider' => $provider,
            'provider_label' => match ($provider) {
                'none' => 'Not Detected',
                'other' => 'Other',
                default => $this->providers[$provider]['label'],
            },
            'provider_name' => $name,
            'status' => $provider === 'none' ? 'not_detected' : 'detected',
            'confidence' => $confidence,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<string>  $suffixes
     */
    private static function matchesSuffix(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            $suffix = strtolower($suffix);

            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
