<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Scraping\Services\CrawlService;
use Illuminate\Console\Command;

class CrawlDueCommand extends Command
{
    protected $signature = 'crawl:due {--source=} {--force}';

    protected $description = 'Crawl due sources within bounded time (for cPanel cron)';

    public function handle(CrawlService $crawl): int
    {
        $this->callSilent('sources:sync');

        $query = Source::query()->where('enabled', true)->orderBy('id');
        if ($slug = $this->option('source')) {
            $query->where('slug', $slug);
        }

        $sources = $query->get()->filter(function (Source $source) {
            return $this->option('force') || $source->isDue();
        });

        if ($sources->isEmpty()) {
            $this->info('No sources due.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->info('Crawling '.$source->slug.'...');
            $result = $crawl->crawlSource($source, 'cron');
            $run = $result['run'];
            $this->line("  → {$run->status} upserted={$run->listings_upserted} parsed={$run->listings_parsed}");
        }

        return self::SUCCESS;
    }
}
