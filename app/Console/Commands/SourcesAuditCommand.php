<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceAudit;
use App\Scraping\AdapterRegistry;
use App\Scraping\Http\HttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SourcesAuditCommand extends Command
{
    protected $signature = 'sources:audit {--source=} {--save-html}';

    protected $description = 'Audit access rules, redirects, listing pages, and field coverage for each source (cPanel-safe)';

    public function handle(HttpClient $http): int
    {
        $this->callSilent('sources:sync');
        $slug = $this->option('source');
        $sources = Source::query()
            ->when($slug, fn ($q) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        $dir = storage_path('app/source-audit/live');
        File::ensureDirectoryExists($dir);
        $auditDate = now()->toDateString();

        $rows = [];
        foreach ($sources as $source) {
            $adapter = AdapterRegistry::make($source->slug);
            $seed = $adapter->listingSeedUrls()[0] ?? $source->base_url;
            $this->info("Auditing {$source->slug} → {$seed}");

            $client = $http->withRateLimit(max(800, (int) $source->rate_limit_ms));
            $result = $client->get($seed);

            $listings = [];
            $parseError = null;
            if ($result->ok()) {
                try {
                    $listings = $adapter->parseListPage($result->body, $result->finalUrl);
                } catch (\Throwable $e) {
                    $parseError = $e->getMessage();
                }
            }

            if ($this->option('save-html') && $result->body !== '') {
                File::put($dir.'/'.$source->slug.'.html', $result->body);
            }

            $fieldCoverage = [
                'sim_number' => collect($listings)->whereNotNull('simNumber')->isNotEmpty(),
                'asking_price' => collect($listings)->whereNotNull('priceAmount')->isNotEmpty(),
                'line_condition' => collect($listings)->contains(fn ($l) => ($l->conditionNormalized ?? 'unknown') !== 'unknown'),
                'city' => collect($listings)->whereNotNull('city')->isNotEmpty(),
                'contacts' => collect($listings)->contains(fn ($l) => $l->contacts !== []),
            ];

            $status = 'ok';
            if (! $result->ok()) {
                $status = $result->status === 0 ? 'timeout' : 'http_error';
            } elseif ($parseError) {
                $status = 'parse_error';
            } elseif ($adapter->availableFields() !== [] && $listings === []) {
                $status = 'parse_empty';
            } elseif ($adapter->availableFields() === []) {
                $status = 'blocked_or_unsupported';
            }

            $evidence = [
                'audit_date' => $auditDate,
                'requested_url' => $seed,
                'final_url' => $result->finalUrl,
                'http_status' => $result->status,
                'elapsed_ms' => $result->elapsedMs,
                'error' => $result->error,
                'parse_error' => $parseError,
                'listings_found' => count($listings),
                'sample_external_ids' => array_slice(array_map(fn ($l) => $l->externalId, $listings), 0, 5),
                'field_coverage' => $fieldCoverage,
                'available_fields_declared' => $adapter->availableFields(),
                'capability_notes' => $adapter->capabilityNotes(),
                'shared_hosting_ok' => $adapter->isFetchableOnSharedHosting(),
                'robots_meta_snippet' => $this->extractRobots($result->body),
            ];

            // Optional detail page check
            if ($listings !== [] && ($listings[0]->canonicalUrl ?? null)) {
                $detailUrl = $listings[0]->canonicalUrl;
                $detail = $client->get($detailUrl);
                $evidence['detail_probe'] = [
                    'url' => $detailUrl,
                    'http_status' => $detail->status,
                    'error' => $detail->error,
                ];
            }

            SourceAudit::query()->create([
                'source_id' => $source->id,
                'domain' => parse_url($source->base_url, PHP_URL_HOST) ?: $source->slug,
                'audit_date' => $auditDate,
                'status' => $status,
                'evidence' => $evidence,
                'notes' => $adapter->capabilityNotes(),
            ]);

            $source->update([
                'last_attempt_at' => now(),
                'last_error' => $result->error ?? $parseError,
                'last_error_type' => match ($status) {
                    'timeout' => 'timeout',
                    'http_error' => 'http',
                    'parse_error', 'parse_empty' => 'parse',
                    'blocked_or_unsupported' => 'blocked',
                    default => null,
                },
                'health_status' => match ($status) {
                    'ok' => 'ok',
                    'parse_empty', 'parse_error' => 'degraded',
                    'blocked_or_unsupported' => 'blocked',
                    default => 'error',
                },
                'listings_found_last_run' => count($listings),
                'fields_available' => array_keys(array_filter($fieldCoverage)),
                'last_success_at' => $result->ok() ? now() : $source->last_success_at,
                'last_parse_success_at' => count($listings) > 0 ? now() : $source->last_parse_success_at,
            ]);

            $rows[] = [
                $source->slug,
                $status,
                (string) $result->status,
                (string) count($listings),
                $fieldCoverage['sim_number'] ? 'Y' : 'N',
                $fieldCoverage['asking_price'] ? 'Y' : 'N',
                $fieldCoverage['line_condition'] ? 'Y' : 'N',
            ];
        }

        $this->table(
            ['source', 'status', 'http', 'listings', 'number', 'price', 'condition'],
            $rows
        );

        File::put(
            storage_path('app/source-audit/live-audit-'.$auditDate.'.json'),
            json_encode(['date' => $auditDate, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return self::SUCCESS;
    }

    private function extractRobots(string $html): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }
}
