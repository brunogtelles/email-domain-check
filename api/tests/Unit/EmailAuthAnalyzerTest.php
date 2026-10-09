<?php

namespace Tests\Unit;

use App\Enums\DnsStatus;
use App\Services\DomainCheck\Dns\DnsLookup;
use App\Services\DomainCheck\EmailAuthAnalyzer;
use PHPUnit\Framework\TestCase;
use React\Dns\Model\Message;

class EmailAuthAnalyzerTest extends TestCase
{
    public function test_it_parses_spf(): void
    {
        $spf = EmailAuthAnalyzer::spf($this->txt(['google-site-verification=abc', 'v=spf1 include:_spf.google.com mx ~all']));

        $this->assertSame('found', $spf['status']);
        $this->assertSame('v=spf1 include:_spf.google.com mx ~all', $spf['value']);
        $this->assertSame('~all (softfail)', $spf['all']);
        $this->assertSame(2, $spf['lookups']);
        $this->assertSame(['_spf.google.com'], $spf['includes']);
        $this->assertSame([], $spf['warnings']);
    }

    public function test_it_flags_spf_problems(): void
    {
        $this->assertSame('missing', EmailAuthAnalyzer::spf($this->txt(['some=thing']))['status']);
        $this->assertSame('missing', EmailAuthAnalyzer::spf(new DnsLookup('example.com', Message::TYPE_TXT, DnsStatus::NoData))['status']);
        $this->assertSame('error', EmailAuthAnalyzer::spf(new DnsLookup('example.com', Message::TYPE_TXT, DnsStatus::Timeout, error: 'timed out'))['status']);

        $multiple = EmailAuthAnalyzer::spf($this->txt(['v=spf1 -all', 'v=spf1 +all']));
        $this->assertSame('multiple', $multiple['status']);

        $permissive = EmailAuthAnalyzer::spf($this->txt(['v=spf1 +all']));
        $this->assertStringContainsString('+all', $permissive['warnings'][0]);
    }

    public function test_it_parses_dmarc(): void
    {
        $dmarc = EmailAuthAnalyzer::dmarc($this->txt(['v=DMARC1; p=reject; sp=quarantine; pct=50; rua=mailto:d@example.com']));

        $this->assertSame('found', $dmarc['status']);
        $this->assertSame('reject', $dmarc['policy']);
        $this->assertSame('quarantine', $dmarc['subdomain_policy']);
        $this->assertSame(50, $dmarc['pct']);
        $this->assertSame('mailto:d@example.com', $dmarc['rua']);
        $this->assertSame(['Policy applies to only 50% of messages.'], $dmarc['warnings']);
    }

    public function test_dmarc_without_a_valid_policy_is_invalid(): void
    {
        $this->assertSame('invalid', EmailAuthAnalyzer::dmarc($this->txt(['v=DMARC1; rua=mailto:d@example.com']))['status']);
        $this->assertSame('missing', EmailAuthAnalyzer::dmarc($this->txt(['v=spf1 -all']))['status']);
    }

    /**
     * @param  list<string>  $records
     */
    private function txt(array $records): DnsLookup
    {
        return new DnsLookup('example.com', Message::TYPE_TXT, DnsStatus::Ok, $records);
    }
}
