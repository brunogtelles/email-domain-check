<?php

namespace App\Services\DomainCheck;

use App\Services\DomainCheck\Dns\DnsLookup;
use App\Services\DomainCheck\Dns\Resolver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use React\Dns\Model\Message;

use function React\Async\await;

/**
 * Checks a domain and its IPv4 addresses against DNS-based blacklists.
 *
 * @phpstan-type BlacklistDefinition array{name: string, zone: string, dqs_zone?: string, target: 'ip'|'domain', error_codes: list<string>, codes: array<string, string>}
 * @phpstan-type TargetResult array{target: string, status: 'listed'|'clean'|'error', codes: list<string>, reason: string|null, details: string|null, error: string|null}
 */
final class BlacklistChecker
{
    /**
     * @param  list<BlacklistDefinition>  $lists
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly CacheRepository $cache,
        private readonly array $lists,
        private readonly ?string $spamhausDqsKey = null,
        private readonly int $cacheTtl = 1800,
    ) {}

    /**
     * @param  list<string>  $ips  IPv4 addresses to check against IP-based lists.
     * @return array{
     *     status: 'clean'|'listed'|'unknown',
     *     listed_on: list<array{list: string, zone: string, target: string, reason: string|null, details: string|null}>,
     *     lists: list<array{name: string, zone: string, target_type: string, status: string, targets: list<TargetResult>}>,
     *     checked_ips: list<string>,
     *     error_count: int,
     * }
     */
    public function check(string $domain, array $ips): array
    {
        $tasks = [];

        foreach ($this->lists as $index => $list) {
            $targets = $list['target'] === 'domain' ? [$domain] : $ips;

            foreach ($targets as $target) {
                $tasks[] = fn (): array => ['index' => $index, ...$this->query($list, $target)];
            }
        }

        $results = Concurrently::run($tasks);

        $lists = [];
        $listedOn = [];
        $errorCount = 0;

        foreach ($this->lists as $index => $list) {
            $targets = array_values(array_map(
                fn (array $result): array => array_diff_key($result, ['index' => true]),
                array_filter($results, fn (array $result): bool => $result['index'] === $index),
            ));

            if ($targets === []) {
                continue;
            }

            $statuses = array_column($targets, 'status');
            $errorCount += count(array_keys($statuses, 'error', true));

            $status = match (true) {
                in_array('listed', $statuses, true) => 'listed',
                in_array('error', $statuses, true) => 'error',
                default => 'clean',
            };

            foreach ($targets as $target) {
                if ($target['status'] === 'listed') {
                    $listedOn[] = [
                        'list' => $list['name'],
                        'zone' => $list['zone'],
                        'target' => $target['target'],
                        'reason' => $target['reason'],
                        'details' => $target['details'],
                    ];
                }
            }

            $lists[] = [
                'name' => $list['name'],
                'zone' => $list['zone'],
                'target_type' => $list['target'],
                'status' => $status,
                'targets' => $targets,
            ];
        }

        $listStatuses = array_column($lists, 'status');

        return [
            'status' => match (true) {
                $listedOn !== [] => 'listed',
                in_array('clean', $listStatuses, true) => 'clean',
                default => 'unknown',
            },
            'listed_on' => $listedOn,
            'lists' => $lists,
            'checked_ips' => array_values($ips),
            'error_count' => $errorCount,
        ];
    }

    /**
     * @param  BlacklistDefinition  $list
     * @return TargetResult
     */
    private function query(array $list, string $target): array
    {
        $cacheKey = 'dnsbl:'.$list['zone'].':'.$target;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $subject = $list['target'] === 'ip' ? implode('.', array_reverse(explode('.', $target))) : $target;
        $queryName = $subject.'.'.$this->zoneFor($list);

        $result = ['target' => $target, ...$this->interpret($list, $queryName, await($this->resolver->lookup($queryName, Message::TYPE_A)))];

        if ($result['status'] !== 'error') {
            $this->cache->put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * @param  BlacklistDefinition  $list
     * @return array{status: 'listed'|'clean'|'error', codes: list<string>, reason: string|null, details: string|null, error: string|null}
     */
    private function interpret(array $list, string $queryName, DnsLookup $lookup): array
    {
        $result = ['status' => 'clean', 'codes' => [], 'reason' => null, 'details' => null, 'error' => null];

        if ($lookup->isMissing()) {
            return $result;
        }

        if ($lookup->hasFailed()) {
            return [...$result, 'status' => 'error', 'error' => 'Lookup '.$lookup->error];
        }

        /** @var list<string> $codes */
        $codes = $lookup->records;

        $invalid = array_filter(
            $codes,
            fn (string $code): bool => ! str_starts_with($code, '127.') || in_array($code, $list['error_codes'], true),
        );

        if ($invalid !== []) {
            return [
                ...$result,
                'status' => 'error',
                'codes' => $codes,
                'error' => 'Query refused or invalid answer ('.implode(', ', $codes).'). The list may block public resolvers; configure DNSBL_NAMESERVERS.',
            ];
        }

        $reasons = array_map(fn (string $code): string => $list['codes'][$code] ?? "Listed ({$code})", $codes);
        $txt = await($this->resolver->lookup($queryName, Message::TYPE_TXT));

        return [
            ...$result,
            'status' => 'listed',
            'codes' => $codes,
            'reason' => implode('; ', array_unique($reasons)),
            'details' => $txt->isOk() ? (string) $txt->records[0] : null,
        ];
    }

    /**
     * @param  BlacklistDefinition  $list
     */
    private function zoneFor(array $list): string
    {
        if ($this->spamhausDqsKey && isset($list['dqs_zone'])) {
            return $this->spamhausDqsKey.'.'.$list['dqs_zone'];
        }

        return $list['zone'];
    }
}
