<?php

namespace Tests\Fakes;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\DnsLookup;
use App\Services\DomainCheck\Dns\Resolver;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * In-memory resolver: unknown names answer NODATA unless configured otherwise.
 */
final class FakeResolver implements Resolver
{
    /** @var array<string, DnsLookup> */
    private array $answers = [];

    /** @var list<string> */
    public array $queries = [];

    public function __construct(private DnsStatus $defaultStatus = DnsStatus::NoData) {}

    /**
     * @param  list<mixed>  $records
     * @param  list<string>  $cnames
     */
    public function add(string $name, int $type, array $records, array $cnames = []): self
    {
        $this->answers[$this->key($name, $type)] = new DnsLookup($name, $type, DnsStatus::Ok, $records, $cnames, 300);

        return $this;
    }

    public function fail(string $name, int $type, DnsStatus $status = DnsStatus::Timeout): self
    {
        $this->answers[$this->key($name, $type)] = new DnsLookup($name, $type, $status, error: $status === DnsStatus::Timeout ? 'timed out' : 'SERVFAIL');

        return $this;
    }

    public function lookup(string $name, int $type): PromiseInterface
    {
        $this->queries[] = $this->key($name, $type);

        return resolve($this->answers[$this->key($name, $type)] ?? new DnsLookup($name, $type, $this->defaultStatus));
    }

    private function key(string $name, int $type): string
    {
        return strtolower($name).'|'.$type;
    }
}
