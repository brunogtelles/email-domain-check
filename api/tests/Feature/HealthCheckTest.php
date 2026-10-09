<?php

namespace Tests\Feature;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\Resolver;
use React\Dns\Model\Message;
use Tests\Fakes\FakeResolver;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    private FakeResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->app->instance(Resolver::class, $this->dns);
    }

    public function test_it_reports_dns_records_and_blacklist_listings(): void
    {
        $this->dns
            ->add('acme.com', Message::TYPE_A, ['203.0.113.10'])
            ->add('acme.com', Message::TYPE_NS, ['ns1.dns.com', 'ns2.dns.com'])
            ->add('acme.com', Message::TYPE_MX, [['priority' => 20, 'target' => 'mx2.acme.com'], ['priority' => 10, 'target' => 'mx1.acme.com']])
            ->add('mx1.acme.com', Message::TYPE_A, ['198.51.100.7'])
            ->add('acme.com', Message::TYPE_TXT, ['v=spf1 mx -all'])
            ->add('_dmarc.acme.com', Message::TYPE_TXT, ['v=DMARC1; p=reject; rua=mailto:d@acme.com'])
            ->add('custom._domainkey.acme.com', Message::TYPE_TXT, ['v=DKIM1; k=rsa; p=MIGfMA0'])
            ->add('10.113.0.203.in-addr.arpa', Message::TYPE_PTR, ['web.acme.com'])
            ->add('7.100.51.198.bl.spamcop.net', Message::TYPE_A, ['127.0.0.2'])
            ->add('7.100.51.198.bl.spamcop.net', Message::TYPE_TXT, ['Blocked - see spamcop.net'])
            ->add('7.100.51.198.zen.spamhaus.org', Message::TYPE_A, ['127.255.255.254']);

        $response = $this->postJson('/api/checks/health', ['input' => 'admin@acme.com', 'selector' => 'custom', 'all_records' => true]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.blacklist.status', 'listed')
            ->assertJsonPath('data.result.blacklist.listed_on.0.list', 'SpamCop')
            ->assertJsonPath('data.result.blacklist.listed_on.0.target', '198.51.100.7')
            ->assertJsonPath('data.result.blacklist.listed_on.0.details', 'Blocked - see spamcop.net')
            ->assertJsonPath('data.result.blacklist.checked_ips', ['198.51.100.7', '203.0.113.10'])
            ->assertJsonPath('data.result.mx.status', 'found')
            ->assertJsonPath('data.result.mx.records.0.host', 'mx1.acme.com')
            ->assertJsonPath('data.result.mx.records.0.ips', ['198.51.100.7'])
            ->assertJsonPath('data.result.spf.status', 'found')
            ->assertJsonPath('data.result.dmarc.policy', 'reject')
            ->assertJsonPath('data.result.dkim.status', 'found')
            ->assertJsonPath('data.result.dkim.records.0.selector', 'custom')
            ->assertJsonPath('data.result.ns.records', ['ns1.dns.com', 'ns2.dns.com'])
            ->assertJsonPath('data.result.records.ptr.0.host', 'web.acme.com');

        $zen = collect($response->json('data.result.blacklist.lists'))->firstWhere('name', 'Spamhaus ZEN');
        $this->assertSame('error', $zen['status'], 'A "query refused" answer must never count as listed.');

        $this->assertContains('MX', array_column($response->json('data.result.all_records'), 'type'));
    }

    public function test_a_clean_domain_without_mail_records(): void
    {
        $this->dns->add('quiet.com', Message::TYPE_A, ['203.0.113.20']);

        $this->postJson('/api/checks/health', ['input' => 'quiet.com'])
            ->assertOk()
            ->assertJsonPath('data.result.blacklist.status', 'clean')
            ->assertJsonPath('data.result.mx.status', 'missing')
            ->assertJsonPath('data.result.spf.status', 'missing')
            ->assertJsonPath('data.result.dmarc.status', 'missing')
            ->assertJsonPath('data.result.dkim.status', 'not_found')
            ->assertJsonPath('data.result.all_records', null);
    }

    public function test_a_non_existent_domain_fails(): void
    {
        $this->app->instance(Resolver::class, new FakeResolver(DnsStatus::NxDomain));

        $this->postJson('/api/checks/health', ['input' => 'nope-nope.com'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Domain does not exist (NXDOMAIN)');
    }

    public function test_a_non_existent_but_listed_domain_completes_with_a_warning(): void
    {
        $dns = (new FakeResolver(DnsStatus::NxDomain))
            ->add('taken-down.com.multi.surbl.org', Message::TYPE_A, ['127.0.0.8']);
        $this->app->instance(Resolver::class, $dns);

        $this->postJson('/api/checks/health', ['input' => 'phish@taken-down.com'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.result.exists', false)
            ->assertJsonPath('data.result.blacklist.status', 'listed')
            ->assertJsonPath('data.result.blacklist.listed_on.0.list', 'SURBL')
            ->assertJsonPath('data.result.errors.0', 'Domain does not exist (NXDOMAIN)');
    }

    public function test_timeouts_are_reported_as_errors(): void
    {
        $this->dns
            ->add('acme.com', Message::TYPE_A, ['203.0.113.10'])
            ->fail('_dmarc.acme.com', Message::TYPE_TXT);

        $this->postJson('/api/checks/health', ['input' => 'acme.com'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.dmarc.status', 'error')
            ->assertJsonPath('data.result.errors.0', 'TXT _dmarc.acme.com: timed out');
    }
}
