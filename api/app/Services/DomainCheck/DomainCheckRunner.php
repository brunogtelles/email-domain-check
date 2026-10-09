<?php

namespace App\Services\DomainCheck;

use App\Enums\CheckType;

final class DomainCheckRunner
{
    public function __construct(
        private readonly HealthChecker $healthChecker,
        private readonly ProviderDetector $providerDetector,
    ) {}

    /**
     * @param  array{selectors?: list<string>, all_records?: bool}  $options
     * @return array{result: array<string, mixed>, failure: string|null, duration_ms: int}
     */
    public function run(CheckType $type, string $domain, array $options = []): array
    {
        $startedAt = hrtime(true);

        $result = match ($type) {
            CheckType::Health => $this->healthChecker->check($domain, $options),
            CheckType::Provider => $this->providerDetector->detect($domain),
        };

        return [
            'result' => $result,
            'failure' => $result['failure'] ?? null,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
