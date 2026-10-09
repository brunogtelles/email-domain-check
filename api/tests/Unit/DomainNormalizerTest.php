<?php

namespace Tests\Unit;

use App\Services\DomainCheck\DomainNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function inputs(): array
    {
        return [
            'bare domain' => ['example.com', 'example.com'],
            'uppercase with trailing dot' => ['Example.COM.', 'example.com'],
            'email' => ['john.doe@Example.com', 'example.com'],
            'named email' => ['John Doe <john@example.co.uk>', 'example.co.uk'],
            'mailto' => ['mailto:sales@example.com', 'example.com'],
            'url with path and port' => ['https://www.example.com:8443/path?q=1', 'www.example.com'],
            'quoted' => ['"example.org"', 'example.org'],
            'idn' => ['café.fr', 'xn--caf-dma.fr'],
            'no tld' => ['localhost', null],
            'ip address' => ['192.168.0.1', null],
            'garbage' => ['not an email', null],
            'empty' => ['   ', null],
            'leading hyphen label' => ['-bad.example.com', null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_it_normalizes_input_to_a_domain(string $input, ?string $expected): void
    {
        $this->assertSame($expected, DomainNormalizer::normalize($input));
    }
}
