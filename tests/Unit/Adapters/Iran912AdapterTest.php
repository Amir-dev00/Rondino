<?php

namespace Tests\Unit\Adapters;

use App\Scraping\Adapters\Iran912Adapter;
use PHPUnit\Framework\TestCase;

/** Fixture-based (not live). */
class Iran912AdapterTest extends TestCase
{
    public function test_parses_data_attributes(): void
    {
        $base = dirname(__DIR__, 2).'/fixtures/sources/iran912.com/';
        $html = is_file($base.'price-list-code-1.html')
            ? file_get_contents($base.'price-list-code-1.html')
            : file_get_contents($base.'list-sample.html');

        $adapter = new Iran912Adapter;
        $listings = $adapter->parseListPage($html, 'https://iran912.com/simcart-912/price-list-code-1/');
        $this->assertNotEmpty($listings);
        $hit = $listings[0];
        foreach ($listings as $listing) {
            if ($listing->simNumber === '09121989327') {
                $hit = $listing;
                break;
            }
        }
        $this->assertMatchesRegularExpression('/^0912[0-9]{7}$/', $hit->simNumber);
        $this->assertNotNull($hit->priceAmount);
    }
}
