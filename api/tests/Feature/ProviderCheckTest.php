<?php

namespace Tests\Feature;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\Resolver;
use React\Dns\Model\Message;
use Tests\Fakes\FakeResolver;
use Tests\TestCase;

class ProviderCheckTest extends TestCase
{
    private FakeResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->app->instance(Resolver::class, $this->dns);
    }

    public function test_it_detects_google_workspace_from_mx_and_extracts_the_domain_from_an_email(): void
    {
        $this->dns
            ->add('acme.com', Message::TYPE_MX, [['priority' => 1, 'target' => 'smtp.google.com']])
            ->add('acme.com', Message::TYPE_TXT, ['v=spf1 include:_spf.google.com ~all']);

        $this->postJson('/api/checks/provider', ['input' => 'Jane <jane@ACME.com>'])
            ->assertOk()
            ->assertJsonPath('data.domain', 'acme.com')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.provider', 'google')
            ->assertJsonPath('data.result.provider_label', 'Google Workspace')
            ->assertJsonPath('data.result.status', 'detected')
            ->assertJsonPath('data.result.confidence', 'high')
            ->assertJsonPath('data.result.mx.0.host', 'smtp.google.com')
            ->assertJsonPath('data.result.evidence.0.signal', 'mx')
            ->assertJsonPath('data.result.evidence.1.detail', 'SPF includes _spf.google.com');
    }

    public function test_it_detects_microsoft_365_behind_a_security_gateway(): void
    {
        $this->dns
            ->add('acme.com', Message::TYPE_MX, [['priority' => 10, 'target' => 'mxa-001.pphosted.com']])
            ->add('acme.com', Message::TYPE_TXT, ['v=spf1 include:spf.protection.outlook.com -all', 'MS=ms12345678'])
            ->add('autodiscover.acme.com', Message::TYPE_CNAME, ['autodiscover.outlook.com']);

        $this->postJson('/api/checks/provider', ['input' => 'acme.com'])
            ->assertOk()
            ->assertJsonPath('data.result.provider', 'microsoft')
            ->assertJsonPath('data.result.provider_name', 'Microsoft 365 (via Proofpoint)')
            ->assertJsonPath('data.result.confidence', 'medium');
    }

    public function test_other_provider_is_reported_by_name(): void
    {
        $this->dns
            ->add('acme.com', Message::TYPE_MX, [['priority' => 10, 'target' => 'mx.zoho.com']])
            ->add('acme.com', Message::TYPE_TXT, ['google-site-verification=a', 'google-site-verification=b', 'google-site-verification=c']);

        $this->postJson('/api/checks/provider', ['input' => 'acme.com'])
            ->assertOk()
            ->assertJsonPath('data.result.provider', 'other')
            ->assertJsonPath('data.result.provider_name', 'Zoho Mail')
            ->assertJsonPath('data.result.reason', 'Primary MX mx.zoho.com belongs to Zoho Mail.');
    }

    public function test_domains_without_mx_or_with_null_mx_are_not_detected(): void
    {
        $this->postJson('/api/checks/provider', ['input' => 'nomail.com'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.provider_label', 'Not Detected')
            ->assertJsonPath('data.result.status', 'not_detected');

        $this->dns->add('nullmx.com', Message::TYPE_MX, [['priority' => 0, 'target' => '']]);

        $this->postJson('/api/checks/provider', ['input' => 'nullmx.com'])
            ->assertJsonPath('data.result.provider', 'none')
            ->assertJsonPath('data.result.reason', 'Null MX (RFC 7505): the domain explicitly does not accept email.');
    }

    public function test_an_mx_timeout_marks_the_check_as_failed(): void
    {
        $this->dns->fail('slow.com', Message::TYPE_MX, DnsStatus::Timeout);

        $this->postJson('/api/checks/provider', ['input' => 'slow.com'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'MX lookup failed: timed out')
            ->assertJsonPath('data.result.errors.0', 'MX slow.com: timed out');
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/checks/provider', ['input' => 'not a domain'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('input');

        $this->postJson('/api/checks/unknown', ['input' => 'acme.com'])->assertNotFound();
    }
}
