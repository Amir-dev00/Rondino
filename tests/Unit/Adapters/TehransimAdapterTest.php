<?php

namespace Tests\Unit\Adapters;

use App\Scraping\Adapters\TehransimAdapter;
use PHPUnit\Framework\TestCase;

/** Fixture-based parser test (not live verification). */
class TehransimAdapterTest extends TestCase
{
    public function test_parses_list_fixture(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2).'/fixtures/sources/tehransim.com/list-sample.html');
        $adapter = new TehransimAdapter;
        $listings = $adapter->parseListPage($html, 'https://tehransim.com/');

        $this->assertGreaterThanOrEqual(3, count($listings));
        $first = $listings[0];
        $this->assertSame('09121275000', $first->simNumber);
        $this->assertSame(7500000000, $first->priceAmount);
        $this->assertSame('used', $first->conditionNormalized);
        $this->assertStringContainsString('/sim-cart/', $first->canonicalUrl);
        $this->assertNotEmpty($first->contacts);
        $this->assertSame('store', $first->contacts[0]['scope']);
    }
}
