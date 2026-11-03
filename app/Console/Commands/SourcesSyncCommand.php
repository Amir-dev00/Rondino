<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Scraping\AdapterRegistry;
use Illuminate\Console\Command;

class SourcesSyncCommand extends Command
{
    protected $signature = 'sources:sync';

    protected $description = 'Upsert the 13 registered sources from config into the database';

    public function handle(): int
    {
        $count = 0;
        foreach (config('rondino.sources') as $meta) {
            $adapter = AdapterRegistry::make($meta['slug']);
            Source::query()->updateOrCreate(
                ['slug' => $meta['slug']],
                [
                    'name' => $meta['name'],
                    'base_url' => $meta['base_url'],
                    'listing_urls' => $meta['listing_urls'],
                    'adapter_class' => $meta['adapter'],
                    'enabled' => true,
                    'refresh_interval_minutes' => $meta['refresh_minutes'],
                    'max_pages_per_run' => $meta['max_pages'],
                    'max_requests_per_run' => $meta['max_requests'],
                    'rate_limit_ms' => $meta['rate_limit_ms'],
                    'fields_available' => $adapter->availableFields(),
                    'capability_notes' => $adapter->capabilityNotes(),
                    'shared_hosting_ok' => $adapter->isFetchableOnSharedHosting(),
                    'health_status' => $adapter->isFetchableOnSharedHosting() ? 'unknown' : 'blocked',
                ]
            );
            $count++;
            $this->line($meta['slug']);
        }

        $this->info("Synced {$count} sources.");

        return self::SUCCESS;
    }
}
