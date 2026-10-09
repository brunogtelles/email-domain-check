<?php

namespace App\Services\DomainCheck\Dns;

use App\Enums\DnsStatus;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\TimeoutException;
use Throwable;

/**
 * The outcome of a single DNS query.
 */
final class DnsLookup
{
    private const TYPE_NAMES = [
        Message::TYPE_A => 'A',
        Message::TYPE_NS => 'NS',
        Message::TYPE_CNAME => 'CNAME',
        Message::TYPE_SOA => 'SOA',
        Message::TYPE_PTR => 'PTR',
        Message::TYPE_MX => 'MX',
        Message::TYPE_TXT => 'TXT',
        Message::TYPE_AAAA => 'AAAA',
        Message::TYPE_SRV => 'SRV',
        Message::TYPE_CAA => 'CAA',
    ];

    private const RCODE_NAMES = [
        Message::RCODE_FORMAT_ERROR => 'FORMERR',
        Message::RCODE_SERVER_FAILURE => 'SERVFAIL',
        Message::RCODE_NOT_IMPLEMENTED => 'NOTIMP',
        Message::RCODE_REFUSED => 'REFUSED',
    ];

    /**
     * @param  list<mixed>  $records  Record data as parsed by react/dns (TXT chunks already joined).
     * @param  list<string>  $cnames  CNAME chain followed to reach the records.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $type,
        public readonly DnsStatus $status,
        public readonly array $records = [],
        public readonly array $cnames = [],
        public readonly ?int $ttl = null,
        public readonly ?string $error = null,
    ) {}

    public static function fromMessage(string $name, int $type, Message $message): self
    {
        if ($message->rcode === Message::RCODE_NAME_ERROR) {
            return new self($name, $type, DnsStatus::NxDomain);
        }

        if ($message->rcode !== Message::RCODE_OK) {
            $rcode = self::RCODE_NAMES[$message->rcode] ?? 'RCODE '.$message->rcode;

            return new self($name, $type, DnsStatus::Error, error: $rcode);
        }

        $cnames = [];
        $current = $name;
        $matching = [];

        for ($depth = 0; $depth < 10; $depth++) {
            $matching = self::recordsFor($message->answers, $current, $type);

            if ($matching !== []) {
                break;
            }

            $alias = self::recordsFor($message->answers, $current, Message::TYPE_CNAME)[0] ?? null;

            if ($alias === null) {
                break;
            }

            $cnames[] = strtolower($alias->data);
            $current = strtolower($alias->data);
        }

        if ($matching === []) {
            return new self($name, $type, DnsStatus::NoData, cnames: $cnames);
        }

        $records = array_map(
            fn (Record $record): mixed => $type === Message::TYPE_TXT ? implode('', $record->data) : $record->data,
            $matching,
        );

        $ttl = min(array_map(fn (Record $record): int => $record->ttl, $matching));

        return new self($name, $type, DnsStatus::Ok, array_values($records), $cnames, $ttl);
    }

    public static function fromException(string $name, int $type, Throwable $exception): self
    {
        $isTimeout = $exception instanceof TimeoutException
            || $exception->getPrevious() instanceof TimeoutException
            || str_contains($exception->getMessage(), 'too many retries')
            || str_contains($exception->getMessage(), 'timed out');

        return $isTimeout
            ? new self($name, $type, DnsStatus::Timeout, error: 'timed out')
            : new self($name, $type, DnsStatus::Error, error: $exception->getMessage());
    }

    public function isOk(): bool
    {
        return $this->status === DnsStatus::Ok;
    }

    /**
     * The name or the record type does not exist (an authoritative "no").
     */
    public function isMissing(): bool
    {
        return $this->status === DnsStatus::NxDomain || $this->status === DnsStatus::NoData;
    }

    /**
     * The query itself failed, so we do not know whether the records exist.
     */
    public function hasFailed(): bool
    {
        return $this->status === DnsStatus::Timeout || $this->status === DnsStatus::Error;
    }

    public function typeName(): string
    {
        return self::TYPE_NAMES[$this->type] ?? (string) $this->type;
    }

    /**
     * Human readable description of the failure, e.g. "TXT _dmarc.example.com: timed out".
     */
    public function errorMessage(): ?string
    {
        return $this->hasFailed() ? "{$this->typeName()} {$this->name}: {$this->error}" : null;
    }

    /**
     * Records formatted as zone-file-like strings.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(fn (mixed $data): string => self::format($this->type, $data), $this->records);
    }

    /**
     * @return array{name: string, type: string, ttl: int|null, values: list<string>, cname: list<string>}
     */
    public function toRecordSet(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->typeName(),
            'ttl' => $this->ttl,
            'values' => $this->values(),
            'cname' => $this->cnames,
        ];
    }

    private static function format(int $type, mixed $data): string
    {
        return match ($type) {
            Message::TYPE_MX => $data['priority'].' '.$data['target'],
            Message::TYPE_SOA => implode(' ', [$data['mname'], $data['rname'], $data['serial'], $data['refresh'], $data['retry'], $data['expire'], $data['minimum']]),
            Message::TYPE_SRV => implode(' ', [$data['priority'], $data['weight'], $data['port'], $data['target']]),
            Message::TYPE_CAA => $data['flag'].' '.$data['tag'].' "'.$data['value'].'"',
            default => is_array($data) ? implode(' ', $data) : (string) $data,
        };
    }

    /**
     * @param  list<Record>  $answers
     * @return list<Record>
     */
    private static function recordsFor(array $answers, string $name, int $type): array
    {
        $name = strtolower($name);

        return array_values(array_filter(
            $answers,
            fn (Record $record): bool => $record->type === $type && strtolower($record->name) === $name,
        ));
    }
}
