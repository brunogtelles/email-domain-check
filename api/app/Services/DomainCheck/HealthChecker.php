<?php

namespace App\Services\DomainCheck;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\DnsLookup;
use App\Services\DomainCheck\Dns\Resolver;
use Illuminate\Container\Attributes\Config;
use React\Dns\Model\Message;

use function React\Async\await;

/**
 * Checks a domain's blacklist (DNSBL) status and its DNS records: MX, SPF, DMARC, DKIM, NS and more.
 */
final class HealthChecker
{
    /**
     * Extra names looked up when "all records" is requested.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const EXTRA_RECORDS = [
        ['', Message::TYPE_CAA],
        ['www', Message::TYPE_A],
        ['www', Message::TYPE_AAAA],
        ['mail', Message::TYPE_A],
        ['autodiscover', Message::TYPE_CNAME],
        ['_mta-sts', Message::TYPE_TXT],
        ['_smtp._tls', Message::TYPE_TXT],
        ['default._bimi', Message::TYPE_TXT],
        ['_autodiscover._tcp', Message::TYPE_SRV],
        ['_submission._tcp', Message::TYPE_SRV],
        ['_imaps._tcp', Message::TYPE_SRV],
        ['_sip._tls', Message::TYPE_SRV],
        ['_sipfederationtls._tcp', Message::TYPE_SRV],
    ];

    /**
     * @param  list<string>  $commonSelectors
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly BlacklistChecker $blacklists,
        #[Config('domaincheck.dkim_selectors')] private readonly array $commonSelectors = [],
        #[Config('domaincheck.max_ips_per_domain')] private readonly int $maxIps = 6,
    ) {}

    /**
     * @param  array{selectors?: list<string>, all_records?: bool}  $options
     * @return array<string, mixed>
     */
    public function check(string $domain, array $options = []): array
    {
        $explicitSelectors = array_values(array_unique(array_filter(array_map(
            fn (string $selector): string => strtolower(trim($selector)),
            $options['selectors'] ?? [],
        ))));
        $selectors = array_values(array_unique([...$explicitSelectors, ...$this->commonSelectors]));
        $allRecords = (bool) ($options['all_records'] ?? false);

        /** @var array{a: DnsLookup, aaaa: DnsLookup, mx: DnsLookup, mx_hosts: array<string, DnsLookup>, txt: DnsLookup, ns: DnsLookup, soa: DnsLookup, cname: DnsLookup, dmarc: DnsLookup, dkim: array<string, DnsLookup>, extra: list<DnsLookup>} $dns */
        $dns = Concurrently::run([
            'a' => $this->resolver->lookup($domain, Message::TYPE_A),
            'aaaa' => $this->resolver->lookup($domain, Message::TYPE_AAAA),
            'mx' => $this->resolver->lookup($domain, Message::TYPE_MX),
            'mx_hosts' => fn (): array => $this->resolveMxHosts($domain),
            'txt' => $this->resolver->lookup($domain, Message::TYPE_TXT),
            'ns' => $this->resolver->lookup($domain, Message::TYPE_NS),
            'soa' => $this->resolver->lookup($domain, Message::TYPE_SOA),
            'cname' => $this->resolver->lookup($domain, Message::TYPE_CNAME),
            'dmarc' => $this->resolver->lookup("_dmarc.{$domain}", Message::TYPE_TXT),
            'dkim' => fn (): array => Concurrently::run(array_combine($selectors, array_map(
                fn (string $selector) => $this->resolver->lookup("{$selector}._domainkey.{$domain}", Message::TYPE_TXT),
                $selectors,
            ))),
            'extra' => fn (): array => $allRecords ? $this->extraRecords($domain) : [],
        ]);

        $mx = $this->mxSummary($dns['mx'], $dns['mx_hosts']);
        $mxIps = array_merge(...array_column($mx['records'], 'ips') ?: [[]]);
        $domainIps = $dns['a']->isOk() ? $dns['a']->records : [];

        $blacklistIps = array_slice(array_values(array_unique([...$mxIps, ...$domainIps])), 0, $this->maxIps);
        $ptrIps = array_slice(array_values(array_unique([...$domainIps, ...($dns['aaaa']->isOk() ? $dns['aaaa']->records : []), ...$mxIps])), 0, $this->maxIps * 2);

        $followUp = Concurrently::run([
            'blacklist' => fn (): array => $this->blacklists->check($domain, $blacklistIps),
            'ptr' => fn (): array => Concurrently::run(array_map(
                fn (string $ip) => $this->resolver->lookup(self::reverseName($ip), Message::TYPE_PTR),
                $ptrIps,
            )),
        ]);

        $ptr = array_map(fn (string $ip, DnsLookup $lookup): array => [
            'ip' => $ip,
            'host' => $lookup->isOk() ? $lookup->records[0] : null,
            'status' => $lookup->status->value,
        ], $ptrIps, $followUp['ptr']);

        $dkim = $this->dkimSummary($dns['dkim'], $explicitSelectors);
        $spf = EmailAuthAnalyzer::spf($dns['txt']);
        $dmarc = EmailAuthAnalyzer::dmarc($dns['dmarc']);

        $core = [$dns['a'], $dns['mx'], $dns['ns'], $dns['soa']];
        $exists = ! collect($core)->every(fn (DnsLookup $lookup): bool => $lookup->status === DnsStatus::NxDomain);
        $allFailed = collect($core)->every(fn (DnsLookup $lookup): bool => $lookup->hasFailed());

        $errors = collect([$dns['a'], $dns['aaaa'], $dns['mx'], $dns['txt'], $dns['ns'], $dns['soa'], $dns['dmarc'], ...array_values($dns['mx_hosts'])])
            ->map(fn (DnsLookup $lookup): ?string => $lookup->errorMessage())
            ->merge($dkim['errors'])
            ->filter()
            ->values();

        if ($followUp['blacklist']['error_count'] > 0) {
            $errors->push("{$followUp['blacklist']['error_count']} blacklist queries failed or were refused");
        }

        unset($dkim['errors']);

        /*
         * Spam / phishing domains are often taken down (NXDOMAIN) while still listed.
         * A listing is a real result, so the check completes and only warns.
         */
        $isListed = $followUp['blacklist']['status'] === 'listed';

        if (! $exists && $isListed) {
            $errors->prepend('Domain does not exist (NXDOMAIN)');
        }

        return [
            'domain' => $domain,
            'exists' => $exists,
            'failure' => match (true) {
                ! $exists && ! $isListed => 'Domain does not exist (NXDOMAIN)',
                $allFailed => 'DNS lookups failed: '.$dns['soa']->error,
                default => null,
            },
            'blacklist' => $followUp['blacklist'],
            'mx' => $mx,
            'spf' => $spf,
            'dmarc' => $dmarc,
            'dkim' => $dkim,
            'ns' => [
                'status' => self::presence($dns['ns']),
                'records' => $dns['ns']->isOk() ? $dns['ns']->records : [],
            ],
            'records' => [
                'a' => $domainIps,
                'aaaa' => $dns['aaaa']->isOk() ? $dns['aaaa']->records : [],
                'cname' => $dns['cname']->isOk() ? $dns['cname']->records : [],
                'txt' => $dns['txt']->isOk() ? $dns['txt']->records : [],
                'soa' => $dns['soa']->isOk() ? $dns['soa']->values()[0] : null,
                'ptr' => $ptr,
            ],
            'all_records' => $allRecords ? $this->recordSets($dns, $dkim, $ptrIps, $followUp['ptr']) : null,
            'errors' => $errors->all(),
        ];
    }

    /**
     * Resolve the IPv4 addresses of every MX host.
     *
     * @return array<string, DnsLookup>
     */
    private function resolveMxHosts(string $domain): array
    {
        $mx = await($this->resolver->lookup($domain, Message::TYPE_MX));

        $hosts = $mx->isOk()
            ? array_values(array_unique(array_filter(array_map(fn (array $record): string => rtrim(strtolower($record['target']), '.'), $mx->records))))
            : [];

        return Concurrently::run(array_combine($hosts, array_map(
            fn (string $host) => $this->resolver->lookup($host, Message::TYPE_A),
            $hosts,
        )));
    }

    /**
     * @param  array<string, DnsLookup>  $hosts
     * @return array{status: string, records: list<array{priority: int, host: string, ips: list<string>}>, error: string|null}
     */
    private function mxSummary(DnsLookup $mx, array $hosts): array
    {
        if (! $mx->isOk()) {
            return ['status' => self::presence($mx), 'records' => [], 'error' => $mx->errorMessage()];
        }

        $records = array_map(function (array $record) use ($hosts): array {
            $host = rtrim(strtolower($record['target']), '.');

            return [
                'priority' => (int) $record['priority'],
                'host' => $host === '' ? '.' : $host,
                'ips' => isset($hosts[$host]) && $hosts[$host]->isOk() ? $hosts[$host]->records : [],
            ];
        }, $mx->records);

        usort($records, fn (array $a, array $b): int => [$a['priority'], $a['host']] <=> [$b['priority'], $b['host']]);

        $isNullMx = count($records) === 1 && $records[0]['host'] === '.';

        return ['status' => $isNullMx ? 'null_mx' : 'found', 'records' => $records, 'error' => null];
    }

    /**
     * @param  array<string, DnsLookup>  $lookups
     * @param  list<string>  $explicitSelectors
     * @return array{status: string, records: list<array<string, mixed>>, selectors_checked: list<string>, explicit_selectors: list<string>, errors: list<string>}
     */
    private function dkimSummary(array $lookups, array $explicitSelectors): array
    {
        $records = [];
        $errors = [];

        foreach ($lookups as $selector => $lookup) {
            $selector = (string) $selector;
            $value = collect($lookup->isOk() ? $lookup->records : [])
                ->first(fn (string $record): bool => preg_match('/(^|;)\s*p\s*=/i', $record) === 1);

            if ($value !== null) {
                preg_match('/(?:^|;)\s*k\s*=\s*([a-z0-9]+)/i', $value, $keyType);
                preg_match('/(?:^|;)\s*p\s*=\s*([^;]*)/i', $value, $publicKey);

                $records[] = [
                    'selector' => $selector,
                    'name' => $lookup->name,
                    'value' => $value,
                    'cname' => $lookup->cnames,
                    'key_type' => strtolower($keyType[1] ?? 'rsa'),
                    'revoked' => trim($publicKey[1] ?? '') === '',
                ];
            } elseif ($lookup->hasFailed() && in_array($selector, $explicitSelectors, true)) {
                $errors[] = (string) $lookup->errorMessage();
            }
        }

        return [
            'status' => match (true) {
                $records !== [] => 'found',
                $errors !== [] => 'error',
                default => 'not_found',
            },
            'records' => $records,
            'selectors_checked' => array_keys($lookups),
            'explicit_selectors' => $explicitSelectors,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<DnsLookup>
     */
    private function extraRecords(string $domain): array
    {
        return Concurrently::run(array_map(
            fn (array $extra) => $this->resolver->lookup(ltrim($extra[0].'.'.$domain, '.'), $extra[1]),
            self::EXTRA_RECORDS,
        ));
    }

    /**
     * Flat list of every record set found, for the "all records" view.
     *
     * @param  array<string, mixed>  $dns
     * @param  array<string, mixed>  $dkim
     * @param  list<string>  $ptrIps
     * @param  list<DnsLookup>  $ptrLookups
     * @return list<array{name: string, type: string, ttl: int|null, values: list<string>, cname: list<string>}>
     */
    private function recordSets(array $dns, array $dkim, array $ptrIps, array $ptrLookups): array
    {
        $dkimLookups = array_intersect_key($dns['dkim'], array_flip(array_column($dkim['records'], 'selector')));

        return collect([
            $dns['a'], $dns['aaaa'], $dns['cname'], $dns['mx'], $dns['ns'], $dns['soa'], $dns['txt'], $dns['dmarc'],
            ...array_values($dkimLookups),
            ...array_values($dns['mx_hosts']),
            ...$ptrLookups,
            ...$dns['extra'],
        ])
            ->filter(fn (DnsLookup $lookup): bool => $lookup->isOk())
            ->map(fn (DnsLookup $lookup): array => $lookup->toRecordSet())
            ->values()
            ->all();
    }

    private static function presence(DnsLookup $lookup): string
    {
        return match (true) {
            $lookup->isOk() => 'found',
            $lookup->hasFailed() => 'error',
            default => 'missing',
        };
    }

    /**
     * Build the in-addr.arpa / ip6.arpa name used for PTR lookups.
     */
    public static function reverseName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $nibbles = str_split(bin2hex((string) inet_pton($ip)));

            return implode('.', array_reverse($nibbles)).'.ip6.arpa';
        }

        return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
    }
}
