<?php

namespace Tests\Unit;

use App\Services\DomainCheck\BulkInputParser;
use PHPUnit\Framework\TestCase;

class BulkInputParserTest extends TestCase
{
    public function test_it_parses_a_plain_text_list_and_removes_duplicates(): void
    {
        $parsed = (new BulkInputParser)->parse("\xEF\xBB\xBFexample.com\r\nuser@example.com\n\nfoo.org\nnot a domain\n");

        $this->assertSame(['example.com', 'foo.org', null], array_column($parsed['entries'], 'domain'));
        $this->assertSame('not a domain', $parsed['entries'][2]['input']);
        $this->assertSame(1, $parsed['duplicates']);
    }

    public function test_it_uses_the_header_to_find_the_email_and_selector_columns(): void
    {
        $csv = "name;E-mail;selector\nJohn;john@acme.com;s1\nAna;\"ana@beta.io\";\n";

        $parsed = (new BulkInputParser)->parse($csv);

        $this->assertSame([
            ['input' => 'john@acme.com', 'domain' => 'acme.com', 'selector' => 's1'],
            ['input' => 'ana@beta.io', 'domain' => 'beta.io', 'selector' => null],
        ], $parsed['entries']);
    }

    public function test_without_a_header_it_picks_the_first_cell_that_looks_like_a_domain(): void
    {
        $parsed = (new BulkInputParser)->parse("John,john@acme.com,123\nAna,https://beta.io/contact,456\n");

        $this->assertSame(['acme.com', 'beta.io'], array_column($parsed['entries'], 'domain'));
    }
}
