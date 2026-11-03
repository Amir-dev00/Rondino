<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Source;
use App\Models\User;
use App\Scraping\Adapters\TehransimAdapter;
use App\Scraping\DTO\ParsedListing;
use App\Scraping\Services\OfferUpserter;
use App\Services\PriceEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferUpsertAndEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_dedupes_and_records_price_history(): void
    {
        $this->artisan('sources:sync');
        $source = Source::query()->where('slug', 'tehransim')->firstOrFail();
        $upserter = app(OfferUpserter::class);

        $listing = new ParsedListing(
            externalId: '09121275000',
            simNumber: '09121275000',
            canonicalUrl: 'https://tehransim.com/sim-cart/0912/code-1/used/09121275000',
            priceAmount: 7500000000,
            priceRaw: '7,500,000,000 تومان',
            priceType: 'fixed',
            conditionRaw: 'کارکرده',
            conditionNormalized: 'used',
            city: null,
            cityRaw: null,
            contacts: [['phone' => '02140446340', 'type' => 'landline', 'scope' => 'store', 'label' => 'store']],
        );

        $upserter->upsertOne($source, $listing);
        $upserter->upsertOne($source, $listing);
        $this->assertSame(1, Offer::query()->count());

        $listing2 = new ParsedListing(
            externalId: '09121275000',
            simNumber: '09121275000',
            canonicalUrl: $listing->canonicalUrl,
            priceAmount: 7400000000,
            priceRaw: '7,400,000,000 تومان',
            priceType: 'fixed',
            conditionRaw: 'کارکرده',
            conditionNormalized: 'used',
            city: null,
            cityRaw: null,
            contacts: $listing->contacts,
        );
        $offer = $upserter->upsertOne($source, $listing2);
        $this->assertSame(1, Offer::query()->count());
        $this->assertSame(7400000000, $offer->price_amount);
        $this->assertGreaterThanOrEqual(2, $offer->priceObservations()->count());
    }

    public function test_empty_parse_does_not_mark_sold(): void
    {
        $this->artisan('sources:sync');
        $source = Source::query()->where('slug', 'tehransim')->firstOrFail();
        $upserter = app(OfferUpserter::class);
        $html = file_get_contents(base_path('tests/fixtures/sources/tehransim.com/list-sample.html'));
        $listings = (new TehransimAdapter)->parseListPage($html, 'https://tehransim.com/');
        $upserter->upsertMany($source, $listings);
        $before = Offer::query()->where('availability', 'available')->count();
        $this->assertGreaterThan(0, $before);

        $changed = $upserter->markMissingAsNotObserved($source, [], now());
        $this->assertSame(0, $changed);
        $this->assertSame(0, Offer::query()->where('availability', 'sold')->count());
    }

    public function test_estimate_insufficient_and_threshold(): void
    {
        $svc = app(PriceEstimateService::class);
        $r = $svc->estimate('09121234567', 'used');
        $this->assertFalse($r['sufficient']);
        $this->assertSame('داده کافی نیست', $r['message']);

        $this->artisan('sources:sync');
        $source = Source::query()->where('slug', 'tehransim')->firstOrFail();
        $upserter = app(OfferUpserter::class);

        for ($i = 0; $i < 6; $i++) {
            $n = sprintf('09121%06d', 100000 + $i);
            $upserter->upsertOne($source, new ParsedListing(
                externalId: $n,
                simNumber: $n,
                canonicalUrl: 'https://tehransim.com/'.$n,
                priceAmount: 1000000000 + ($i * 10000000),
                priceRaw: 'x تومان',
                priceType: 'fixed',
                conditionRaw: 'کارکرده',
                conditionNormalized: 'used',
                city: null,
                cityRaw: null,
            ));
        }

        $ok = $svc->estimate('09121111111', 'used');
        $this->assertTrue($ok['sufficient']);
        $this->assertNotNull($ok['low']);
        $this->assertNotNull($ok['high']);
        $this->assertGreaterThanOrEqual(5, $ok['sample_size']);
    }

    public function test_manual_refresh_schedules_job_not_inline_crawl(): void
    {
        $this->artisan('sources:sync');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $source = Source::query()->where('slug', 'tehransim')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.sources.refresh', $source))
            ->assertRedirect();

        $this->assertDatabaseHas('crawl_runs', [
            'source_id' => $source->id,
            'status' => 'pending',
            'trigger' => 'manual',
        ]);
    }
}
