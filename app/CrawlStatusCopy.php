<?php

declare(strict_types=1);

/**
 * User-facing Persian copy for crawl / source statuses and stop reasons.
 * Internal machine codes stay in logs; the panel only shows these strings.
 */
final class CrawlStatusCopy
{
    public const STATUS_RECEIVING = 'receiving';
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_USER_STOPPED = 'user_stopped';
    public const STATUS_PROBLEM = 'problem';
    public const STATUS_NEVER = 'never';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_UNKNOWN = 'unknown';

    public const MODE_QUICK = 'incremental';
    public const MODE_FULL = 'full';

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        self::STATUS_RECEIVING => 'در حال دریافت اطلاعات',
        self::STATUS_UP_TO_DATE => 'بررسی کامل شد',
        self::STATUS_PARTIAL => 'بخشی از اطلاعات بررسی شد',
        self::STATUS_PAUSED => 'موقتاً متوقف شده',
        self::STATUS_USER_STOPPED => 'توسط کاربر متوقف شد',
        self::STATUS_PROBLEM => 'مشکل در دریافت اطلاعات',
        self::STATUS_NEVER => 'در انتظار بررسی',
        self::STATUS_STOPPED => 'متوقف شده',
        self::STATUS_UNKNOWN => 'وضعیت دریافت اطلاعات مشخص نیست',
    ];

    /** @var array<string, string> */
    private const MODE_LABELS = [
        self::MODE_QUICK => 'به‌روزرسانی سریع',
        self::MODE_FULL => 'بررسی کامل سایت',
    ];

    /**
     * Primary stop reasons → [summary, next, forces_category|null].
     * @var array<string, array{0:string,1:string,2:?string}>
     */
    private const STOP_REASONS = [
        'pagination_exhausted' => [
            'بررسی این سایت تمام شد.',
            'اطلاعات جدید ذخیره شده‌اند.',
            self::STATUS_UP_TO_DATE,
        ],
        'incremental_overlap_reached' => [
            'بررسی آگهی‌های تازه تمام شد.',
            'ادامه از نوبت بعد ممکن است.',
            self::STATUS_UP_TO_DATE,
        ],
        'incremental_page_cap' => [
            'این مرحله از بررسی تمام شد.',
            'برای ادامه، دوباره به‌روزرسانی کنید.',
            self::STATUS_PARTIAL,
        ],
        'incomplete_inventory_pages' => [
            'فقط بخشی از آگهی‌های سایت بررسی شده‌اند.',
            'برای بررسی بقیه، دریافت اطلاعات را ادامه دهید.',
            self::STATUS_PARTIAL,
        ],
        'premature_pagination_end' => [
            'فهرست سایت زودتر از انتظار تمام شد.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PARTIAL,
        ],
        'duplicate_next_url' => [
            'ادامهٔ صفحات سایت نامعتبر بود.',
            'دوباره به‌روزرسانی کنید.',
            self::STATUS_PROBLEM,
        ],
        'all_branches_failed' => [
            'ارتباط با سایت برقرار نشد.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PROBLEM,
        ],
        'branch_failed' => [
            'ارتباط با سایت برقرار نشد.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PROBLEM,
        ],
        'evidence_wave_budget' => [
            'این مرحله از بررسی تمام شد.',
            'دریافت اطلاعات از همین‌جا قابل ادامه است.',
            self::STATUS_PARTIAL,
        ],
        'manual_stop' => [
            'بررسی این سایت توسط کاربر متوقف شد.',
            'هر زمان بخواهید می‌توانید دوباره شروع کنید.',
            self::STATUS_USER_STOPPED,
        ],
        'rate_limit' => [
            'سایت موقتاً اجازه دریافت اطلاعات بیشتر نمی‌دهد.',
            'کمی صبر کنید و دوباره تلاش کنید.',
            self::STATUS_PAUSED,
        ],
        'timeout' => [
            'سایت در زمان مناسب پاسخ نداد.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PROBLEM,
        ],
        'proxy_error' => [
            'ارتباط با سایت برقرار نشد.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PROBLEM,
        ],
        'challenge' => [
            'سایت برای ادامه دریافت اطلاعات محدودیت ایجاد کرده است.',
            'کمی بعد دوباره تلاش کنید.',
            self::STATUS_PROBLEM,
        ],
        'no_new_items' => [
            'آگهی جدیدی پیدا نشد.',
            'نیازی به کار فوری نیست.',
            self::STATUS_UP_TO_DATE,
        ],
        'scheduled_pause' => [
            'دریافت اطلاعات تا نوبت بعدی متوقف شده است.',
            'سیستم در زمان مقرر ادامه می‌دهد.',
            self::STATUS_PAUSED,
        ],
    ];

    public static function statusLabel(string $userStatus): string
    {
        return self::STATUS_LABELS[$userStatus] ?? self::STATUS_LABELS[self::STATUS_UNKNOWN];
    }

    public static function modeLabel(?string $crawlMode): string
    {
        if ($crawlMode === self::MODE_QUICK) {
            return self::MODE_LABELS[self::MODE_QUICK];
        }
        if ($crawlMode === self::MODE_FULL) {
            return self::MODE_LABELS[self::MODE_FULL];
        }
        return '';
    }

    /**
     * Build user-facing fields for a progress snapshot.
     *
     * @param array<string,mixed> $snap Partial snapshot (status, stop_reason, pages, totals, …)
     * @return array{
     *   user_status:string,
     *   user_status_label:string,
     *   user_summary:string,
     *   user_next:string,
     *   user_activity:string,
     *   status_fa:string,
     *   stop_reason_raw:?string,
     *   progress_pct:?int
     * }
     */
    public static function enrich(array $snap): array
    {
        $passStatus = (string) ($snap['status'] ?? 'idle');
        $rawStop = self::normalizeStopReason($snap['stop_reason'] ?? null);
        $hasSuccess = !empty($snap['last_success_at']);
        $pagesFetched = (int) ($snap['pages_fetched'] ?? 0);
        $totalPages = isset($snap['source_total_pages']) ? (int) $snap['source_total_pages'] : 0;
        $offersPass = (int) ($snap['unique_offers_pass'] ?? $snap['offers_found'] ?? 0);
        $inserted = (int) ($snap['inserted_pass'] ?? 0);
        $lastError = trim((string) ($snap['last_error'] ?? ''));

        $stopInfo = self::describeStopReasons($rawStop);
        $userStatus = self::classify($passStatus, $stopInfo['forced_status'], $hasSuccess, $lastError !== '');

        $summary = $stopInfo['summary'];
        $next = $stopInfo['next'];
        $labelOverride = null;

        if ($summary === '') {
            $summary = match ($userStatus) {
                self::STATUS_RECEIVING => 'در حال دریافت آگهی‌ها از این سایت.',
                self::STATUS_PAUSED => 'دریافت اطلاعات فعلاً متوقف شده و از مرحله بعد قابل ادامه است.',
                self::STATUS_UP_TO_DATE => $hasSuccess
                    ? 'بررسی این سایت تمام شد. اطلاعات جدید ذخیره شده‌اند.'
                    : 'این سایت آماده دریافت اطلاعات است.',
                self::STATUS_PARTIAL => 'بررسی این سایت متوقف شده است. بخشی از آگهی‌ها هنوز بررسی نشده‌اند.',
                self::STATUS_USER_STOPPED => 'بررسی این سایت توسط کاربر متوقف شد.',
                self::STATUS_PROBLEM => $lastError !== ''
                    ? 'در دریافت اطلاعات مشکلی پیش آمد.'
                    : 'دریافت اطلاعات با مشکل روبه‌رو شد.',
                self::STATUS_NEVER => 'هنوز هیچ بررسی موفقی برای این سایت ثبت نشده است.',
                self::STATUS_STOPPED => 'دریافت اطلاعات از این سایت خاموش است.',
                default => 'وضعیت دریافت اطلاعات مشخص نیست.',
            };
        }

        if ($next === '') {
            $next = match ($userStatus) {
                self::STATUS_RECEIVING => 'لطفاً صبر کنید تا این مرحله تمام شود.',
                self::STATUS_PAUSED, self::STATUS_PARTIAL, self::STATUS_USER_STOPPED => 'برای ادامه، «به‌روزرسانی سریع» یا «بررسی کامل سایت» را بزنید.',
                self::STATUS_PROBLEM => 'کمی بعد دوباره تلاش کنید.',
                self::STATUS_NEVER => 'برای شروع، «به‌روزرسانی سریع» را بزنید.',
                self::STATUS_STOPPED => 'این سایت در پنل غیرفعال است.',
                self::STATUS_UP_TO_DATE => 'برای آگهی‌های تازه، «به‌روزرسانی سریع» کافی است.',
                default => '',
            };
        }

        // After a finished successful pass, resting state reads as current — not a perpetual "complete" banner.
        if (
            $userStatus === self::STATUS_UP_TO_DATE
            && $hasSuccess
            && in_array($passStatus, ['idle', 'completed'], true)
        ) {
            $labelOverride = 'به‌روز';
        }

        $activity = '';
        if ($userStatus === self::STATUS_RECEIVING || $userStatus === self::STATUS_PAUSED) {
            $bits = [];
            if ($offersPass > 0) {
                $bits[] = 'آگهی‌های این بررسی: ' . number_format($offersPass);
            }
            if ($inserted > 0) {
                $bits[] = 'آگهی‌های جدید: ' . number_format($inserted);
            }
            if ($pagesFetched > 0) {
                $bits[] = $totalPages > 0
                    ? ('صفحه ' . number_format($pagesFetched) . ' از ' . number_format($totalPages))
                    : ('صفحات بررسی‌شده: ' . number_format($pagesFetched));
            }
            $activity = $bits !== [] ? implode(' · ', $bits) : 'دریافت اطلاعات در جریان است.';
        }

        $progressPct = null;
        if ($totalPages > 0 && $pagesFetched >= 0) {
            $progressPct = (int) min(100, max(0, (int) round(($pagesFetched / $totalPages) * 100)));
            if ($userStatus === self::STATUS_RECEIVING && $progressPct >= 100) {
                $progressPct = 99;
            }
        }

        $label = $labelOverride ?? self::statusLabel($userStatus);
        $crawlMode = (($snap['crawl_mode'] ?? '') === self::MODE_QUICK)
            ? self::MODE_QUICK
            : ((($snap['crawl_mode'] ?? '') === self::MODE_FULL) ? self::MODE_FULL : null);

        return [
            'user_status' => $userStatus,
            'user_status_label' => $label,
            'user_summary' => $summary,
            'user_next' => $next,
            'user_activity' => $activity,
            'status_fa' => $label,
            'stop_reason_raw' => $rawStop,
            'progress_pct' => $progressPct,
            'crawl_mode' => $crawlMode,
            'crawl_mode_fa' => self::modeLabel($crawlMode),
        ];
    }

    public static function classify(
        string $passStatus,
        ?string $forcedFromStop,
        bool $hasSuccess,
        bool $hasError
    ): string {
        if ($passStatus === 'disabled') {
            return self::STATUS_STOPPED;
        }
        if ($passStatus === 'running') {
            return self::STATUS_RECEIVING;
        }
        if ($passStatus === 'paused') {
            return $forcedFromStop === self::STATUS_USER_STOPPED
                ? self::STATUS_USER_STOPPED
                : self::STATUS_PAUSED;
        }
        if ($passStatus === 'blocked') {
            return self::STATUS_PROBLEM;
        }
        if ($passStatus === 'partial') {
            return $forcedFromStop ?: self::STATUS_PARTIAL;
        }
        if ($passStatus === 'completed') {
            if ($forcedFromStop === self::STATUS_PARTIAL || $forcedFromStop === self::STATUS_PROBLEM) {
                return $forcedFromStop;
            }
            return self::STATUS_UP_TO_DATE;
        }
        if ($passStatus === 'idle') {
            if ($hasError && !$hasSuccess) {
                return self::STATUS_PROBLEM;
            }
            return $hasSuccess ? self::STATUS_UP_TO_DATE : self::STATUS_NEVER;
        }
        if ($forcedFromStop) {
            return $forcedFromStop;
        }
        return self::STATUS_UNKNOWN;
    }

    /**
     * @return array{summary:string,next:string,forced_status:?string,primary:?string}
     */
    public static function describeStopReasons(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['summary' => '', 'next' => '', 'forced_status' => null, 'primary' => null];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
        if ($parts === []) {
            return ['summary' => '', 'next' => '', 'forced_status' => null, 'primary' => null];
        }

        $priority = [
            'all_branches_failed',
            'branch_failed',
            'proxy_error',
            'timeout',
            'challenge',
            'rate_limit',
            'duplicate_next_url',
            'incomplete_inventory_pages',
            'premature_pagination_end',
            'incremental_page_cap',
            'evidence_wave_budget',
            'manual_stop',
            'scheduled_pause',
            'incremental_overlap_reached',
            'no_new_items',
            'pagination_exhausted',
        ];
        usort($parts, static function (string $a, string $b) use ($priority): int {
            $ia = array_search($a, $priority, true);
            $ib = array_search($b, $priority, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;
            return $ia <=> $ib;
        });

        $primary = $parts[0];
        if (isset(self::STOP_REASONS[$primary])) {
            [$summary, $next, $forced] = self::STOP_REASONS[$primary];
            return [
                'summary' => $summary,
                'next' => $next,
                'forced_status' => $forced,
                'primary' => $primary,
            ];
        }

        return [
            'summary' => 'دریافت اطلاعات متوقف شد.',
            'next' => 'در صورت نیاز دوباره به‌روزرسانی کنید.',
            'forced_status' => null,
            'primary' => $primary,
        ];
    }

    /** @return list<string> */
    public static function knownStopReasons(): array
    {
        return array_keys(self::STOP_REASONS);
    }

    private static function normalizeStopReason(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        return $s === '' ? null : $s;
    }
}
