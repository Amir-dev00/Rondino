<?php

namespace App\Console\Commands;

use App\Models\CrawlRun;
use App\Models\Source;
use App\Scraping\Services\CrawlService;
use Illuminate\Console\Command;

class CrawlScheduledCommand extends Command
{
    protected $signature = 'crawl:scheduled';

    protected $description = 'Process one pending manual crawl job, then due sources (cron entrypoint)';

    public function handle(CrawlService $crawl): int
    {
        $pending = CrawlRun::query()
            ->where('status', 'pending')
            ->where('trigger', 'manual')
            ->orderBy('id')
            ->first();

        if ($pending) {
            $source = $pending->source;
            $pending->delete();
            $this->info('Manual job for '.$source->slug);
            $crawl->crawlSource($source, 'manual');
        }

        $this->call('crawl:due');

        return self::SUCCESS;
    }
}
