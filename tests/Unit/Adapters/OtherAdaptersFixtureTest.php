<?php

namespace Tests\Unit\Adapters;

use App\Scraping\Adapters\CafesimcartAdapter;
use App\Scraping\Adapters\DonyayekhatAdapter;
use App\Scraping\Adapters\JamsimkartAdapter;
use App\Scraping\Adapters\RondbazAdapter;
use App\Scraping\Adapters\RondIrAdapter;
use App\Scraping\Adapters\SetaresimAdapter;
use App\Scraping\Adapters\SimkhanAdapter;
use PHPUnit\Framework\TestCase;

/** Fixture-based adapter smoke tests. */
class OtherAdaptersFixtureTest extends TestCase
{
    private function fx(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/fixtures/sources/'.$path);
    }

    public function test_cafe_and_jam_and_rondbaz_parse(): void
    {
        $cafe = (new CafesimcartAdapter)->parseListPage(
            $this->fx('cafesimcart.com/list-sample.html'),
            'https://cafesimcart.com/'
        );
        $this->assertNotEmpty($cafe);
        $this->assertSame('09121008487', $cafe[0]->simNumber);

        $jam = (new JamsimkartAdapter)->parseListPage(
            $this->fx('www.jamsimkart.com/list-sample.html'),
            'https://www.jamsimkart.com/'
        );
        $this->assertNotEmpty($jam);
        $this->assertSame('09121199011', $jam[0]->simNumber);
        $this->assertSame('like_new', $jam[0]->conditionNormalized);

        $rondbazHtml = $this->fx('www.rondbaz.com/mci-0912.html');
        if (strlen($rondbazHtml) < 500) {
            $rondbazHtml = $this->fx('www.rondbaz.com/list-sample.html');
        }
        $rb = (new RondbazAdapter)->parseListPage($rondbazHtml, 'https://www.rondbaz.com/');
        foreach ($rb as $listing) {
            $this->assertMatchesRegularExpression('/^0912[0-9]{7}$/', $listing->simNumber);
        }
    }

    public function test_blocked_adapters_return_empty(): void
    {
        $this->assertSame([], (new RondIrAdapter)->parseListPage('<html></html>', 'https://rond.ir'));
        $this->assertSame([], (new SetaresimAdapter)->parseListPage('<html></html>', 'https://setaresim.com'));
        $this->assertSame([], (new SimkhanAdapter)->parseListPage(
            $this->fx('simkhan.ir/list-sample.html'),
            'https://simkhan.ir/simcard912'
        ));
        $this->assertSame([], (new DonyayekhatAdapter)->parseListPage(
            $this->fx('donyayekhat0912.com/list-sample.html'),
            'https://donyayekhat0912.com/'
        ));
    }
}
