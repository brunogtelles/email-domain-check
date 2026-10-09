<?php

namespace App\Services\DomainCheck\Dns;

use React\Promise\PromiseInterface;

interface Resolver
{
    /**
     * Look up records of the given type. The promise never rejects: failures
     * are reported through the lookup status.
     *
     * @param  int  $type  One of the React\Dns\Model\Message::TYPE_* constants.
     * @return PromiseInterface<DnsLookup>
     */
    public function lookup(string $name, int $type): PromiseInterface;
}
