<?php

namespace App\Services\DomainCheck\Dns;

use InvalidArgumentException;
use React\Cache\ArrayCache;
use React\Dns\Model\Message;
use React\Dns\Query\CachingExecutor;
use React\Dns\Query\CoopExecutor;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\FallbackExecutor;
use React\Dns\Query\Query;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\TcpTransportExecutor;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\UdpTransportExecutor;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Non-blocking resolver built on react/dns.
 *
 * Each nameserver gets its own timeout + retry chain (UDP with TCP fallback for
 * truncated answers); when one nameserver keeps failing the next one is used.
 * Identical in-flight queries are merged and answers are cached by TTL.
 */
final class ReactDnsResolver implements Resolver
{
    private ExecutorInterface $executor;

    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(array $nameservers, float $timeout = 2.0, int $retries = 1)
    {
        $executor = null;

        foreach (array_reverse($nameservers) as $nameserver) {
            $transport = new SelectiveTransportExecutor(
                new UdpTransportExecutor($nameserver),
                new TcpTransportExecutor($nameserver),
            );

            $chain = new RetryExecutor(new TimeoutExecutor($transport, $timeout), $retries);
            $executor = $executor === null ? $chain : new FallbackExecutor($chain, $executor);
        }

        if ($executor === null) {
            throw new InvalidArgumentException('At least one DNS nameserver must be configured.');
        }

        $this->executor = new CachingExecutor(new CoopExecutor($executor), new ArrayCache(5000));
    }

    public function lookup(string $name, int $type): PromiseInterface
    {
        $name = rtrim(strtolower($name), '.');

        return $this->executor->query(new Query($name, $type, Message::CLASS_IN))->then(
            fn (Message $message): DnsLookup => DnsLookup::fromMessage($name, $type, $message),
            fn (Throwable $exception): DnsLookup => DnsLookup::fromException($name, $type, $exception),
        );
    }
}
