<?php

declare(strict_types=1);

/**
 * Server-side inventory queries for the market intelligence UI.
 * All counts and lists come from MySQL — never hard-coded demo totals.
 */
final class Inventory
{
    /**
     * Sources hidden from the normal panel / crawl UI.
     * Historical MySQL rows (offers, contacts, prices) are preserved.
     * @var list<string>
     */
    public const PANEL_HIDDEN_SLUGS = ['setaresim'];

    public static function isHiddenFromPanel(string $slug): bool
    {
        return in_array($slug, self::PANEL_HIDDEN_SLUGS, true);
    }

    /** SQL fragment: s.slug NOT IN (...hidden...) */
    public static function panelVisibleSlugSql(string $alias = 's'): string
    {
        $parts = [];
        foreach (self::PANEL_HIDDEN_SLUGS as $slug) {
            $parts[] = Database::pdo()->quote($slug);
        }
        if ($parts === []) {
            return '1=1';
        }
        return $alias . '.slug NOT IN (' . implode(',', $parts) . ')';
    }

    /** @return array<string, mixed> */
    public static function parseFilters(array $extra = []): array
    {
        $f = [
            'q' => trim((string) (get_q('q', '') ?? '')),
            'phone' => trim((string) (get_q('phone', '') ?? '')),
            'code' => trim((string) (get_q('code', '') ?? '')),
            'condition' => trim((string) (get_q('condition', '') ?? '')),
            'city' => trim((string) (get_q('city', '') ?? '')),
            'source' => trim((string) (get_q('source', '') ?? '')),
            'slug' => trim((string) (get_q('slug', '') ?? '')),
            'availability' => trim((string) (get_q('availability', '') ?? '')),
            'has_contact' => trim((string) (get_q('has_contact', '') ?? '')),
            'price_type' => trim((string) (get_q('price_type', '') ?? '')),
            'contact_scope' => trim((string) (get_q('contact_scope', '') ?? '')),
            'min' => trim((string) (get_q('min', '') ?? '')),
            'max' => trim((string) (get_q('max', '') ?? '')),
            'seen_from' => trim((string) (get_q('seen_from', '') ?? '')),
            'seen_to' => trim((string) (get_q('seen_to', '') ?? '')),
            'sort' => trim((string) (get_q('sort', '') ?? '')),
        ];
        foreach ($extra as $k => $v) {
            $f[$k] = $v;
        }
        return $f;
    }

    /** @return array{ok:bool,error:?string,from:?string,to:?string} */
    public static function resolveDateRange(array $f): array
    {
        $from = null;
        $to = null;
        if ($f['seen_from'] !== '') {
            $rs = Jalali::rangeStartUtc($f['seen_from']);
            if (!$rs['ok']) {
                return ['ok' => false, 'error' => $rs['error'] ?? 'تاریخ نامعتبر', 'from' => null, 'to' => null];
            }
            $from = $rs['utc'];
        }
        if ($f['seen_to'] !== '') {
            $re = Jalali::rangeEndUtc($f['seen_to']);
            if (!$re['ok']) {
                return ['ok' => false, 'error' => $re['error'] ?? 'تاریخ نامعتبر', 'from' => null, 'to' => null];
            }
            $to = $re['utc'];
        }
        return ['ok' => true, 'error' => null, 'from' => $from, 'to' => $to];
    }

    /**
     * @param list<string> $omit
     * @return array<string, scalar>
     */
    public static function queryParams(array $f, string $route, array $omit = []): array
    {
        $all = [
            'r' => $route,
            'q' => $f['q'] ?? '',
            'phone' => $f['phone'] ?? '',
            'code' => $f['code'] ?? '',
            'condition' => $f['condition'] ?? '',
            'city' => $f['city'] ?? '',
            'source' => $f['source'] ?? '',
            'slug' => $f['slug'] ?? '',
            'availability' => $f['availability'] ?? '',
            'has_contact' => $f['has_contact'] ?? '',
            'price_type' => $f['price_type'] ?? '',
            'contact_scope' => $f['contact_scope'] ?? '',
            'min' => $f['min'] ?? '',
            'max' => $f['max'] ?? '',
            'seen_from' => $f['seen_from'] ?? '',
            'seen_to' => $f['seen_to'] ?? '',
            'sort' => $f['sort'] ?? '',
            'per' => get_q('per', '') ?? '',
        ];
        foreach ($omit as $k) {
            unset($all[$k]);
        }
        return array_filter($all, static fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Sources shown in the panel (nav, filters, crawl dashboard).
     * Hidden sources such as setaresim are omitted; DB rows remain intact.
     * @return list<array<string,mixed>>
     */
    public static function allSources(): array
    {
        $sql = 'SELECT id, slug, name, base_url, list_url, is_enabled, refresh_interval_minutes,
                       last_success_at, last_attempt_at, last_error, last_offers_found
                FROM sources s
                WHERE ' . self::panelVisibleSlugSql('s') . '
                ORDER BY name';
        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * @param bool $allowHidden When false (default), panel-hidden sources resolve as not found.
     */
    public static function sourceBySlug(string $slug, bool $allowHidden = false): ?array
    {
        if (!$allowHidden && self::isHiddenFromPanel($slug)) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM sources WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public static function dashboardStats(): array
    {
        $cacheFile = base_path('storage/cache/dashboard-stats.json');
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $pdo = Database::pdo();
        $stats = [
            'unique_sims' => (int) $pdo->query('SELECT COUNT(*) FROM sim_numbers')->fetchColumn(),
            'total_listings' => (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn(),
            'total_contacts' => (int) $pdo->query('SELECT COUNT(*) FROM offer_contacts')->fetchColumn(),
            'active_sources' => (int) $pdo->query(
                'SELECT COUNT(*) FROM sources s WHERE s.is_enabled = 1 AND ' . self::panelVisibleSlugSql('s')
            )->fetchColumn(),
            'listings_with_contact' => (int) $pdo->query(
                'SELECT COUNT(DISTINCT o.id) FROM offers o INNER JOIN offer_contacts oc ON oc.offer_id = o.id'
            )->fetchColumn(),
            'latest_inventory_at' => $pdo->query('SELECT MAX(last_checked_at) FROM offers')->fetchColumn() ?: null,
            'latest_contact_at' => null,
        ];
        $stats['listings_without_contact'] = $stats['total_listings'] - $stats['listings_with_contact'];

        try {
            $stats['latest_contact_at'] = $pdo->query(
                "SELECT MAX(checked_at) FROM rond_contact_checks WHERE status = 'verified'"
            )->fetchColumn() ?: null;
        } catch (Throwable) {
            $stats['latest_contact_at'] = $pdo->query(
                'SELECT MAX(o.last_checked_at) FROM offers o INNER JOIN offer_contacts oc ON oc.offer_id = o.id'
            )->fetchColumn() ?: null;
        }

        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, json_encode($stats, JSON_UNESCAPED_UNICODE));

        return $stats;
    }

    /** @return array{labels:list<string>,listings:list<int>,contacts:list<int>,slugs:list<string>} */
    public static function chartBySource(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT s.slug, s.name,
                    COUNT(DISTINCT o.id) AS listings,
                    COUNT(DISTINCT CASE WHEN oc.id IS NOT NULL THEN o.id END) AS with_contact
             FROM sources s
             LEFT JOIN offers o ON o.source_id = s.id
             LEFT JOIN offer_contacts oc ON oc.offer_id = o.id
             WHERE ' . self::panelVisibleSlugSql('s') . '
             GROUP BY s.id, s.slug, s.name
             ORDER BY listings DESC'
        )->fetchAll();
        $labels = [];
        $listings = [];
        $contacts = [];
        $slugs = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r['name'];
            $listings[] = (int) $r['listings'];
            $contacts[] = (int) $r['with_contact'];
            $slugs[] = (string) $r['slug'];
        }
        return compact('labels', 'listings', 'contacts', 'slugs');
    }

    /** @return array{labels:list<string>,counts:list<int>} */
    public static function chartPriceDistribution(): array
    {
        $pdo = Database::pdo();
        $buckets = [
            'زیر ۵۰م' => [0, 50_000_000],
            '۵۰–۱۰۰م' => [50_000_000, 100_000_000],
            '۱۰۰–۲۰۰م' => [100_000_000, 200_000_000],
            '۲۰۰–۵۰۰م' => [200_000_000, 500_000_000],
            '۵۰۰م–۱م' => [500_000_000, 1_000_000_000],
            'بالای ۱م' => [1_000_000_000, null],
        ];
        $labels = [];
        $counts = [];
        foreach ($buckets as $label => [$lo, $hi]) {
            if ($hi === null) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM offers WHERE price_amount IS NOT NULL AND price_amount >= ?');
                $stmt->execute([$lo]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM offers WHERE price_amount IS NOT NULL AND price_amount >= ? AND price_amount < ?'
                );
                $stmt->execute([$lo, $hi]);
            }
            $labels[] = $label;
            $counts[] = (int) $stmt->fetchColumn();
        }
        return compact('labels', 'counts');
    }

    /** @return array{labels:list<string>,counts:list<int>} */
    public static function chartInventoryGrowth(int $days = 30): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT DATE(first_seen_at) AS d, COUNT(*) AS c
             FROM offers
             WHERE first_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
             GROUP BY DATE(first_seen_at)
             ORDER BY d ASC"
        );
        $stmt->execute([$days]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(string) $r['d']] = (int) $r['c'];
        }
        $labels = [];
        $counts = [];
        $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $start->modify("-{$i} days")->format('Y-m-d');
            $labels[] = $d;
            $counts[] = $map[$d] ?? 0;
        }
        return compact('labels', 'counts');
    }

    /** @return array{labels:list<string>,verified:list<int>,pending:list<int>} */
    public static function chartContactProgress(): array
    {
        $bySource = self::chartBySource();
        $pending = [];
        foreach ($bySource['listings'] as $i => $listings) {
            $pending[] = max(0, (int) $listings - (int) $bySource['contacts'][$i]);
        }
        return [
            'labels' => $bySource['labels'],
            'verified' => $bySource['contacts'],
            'pending' => $pending,
        ];
    }

    /** @return array<string,mixed> */
    public static function sourceStats(int $sourceId): array
    {
        $all = self::allSourceStats();
        return $all[$sourceId] ?? [
            'listings' => 0,
            'unique_sims' => 0,
            'with_contact' => 0,
            'without_contact' => 0,
            'phone_records' => 0,
            'latest_listing_at' => null,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public static function allSourceStats(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT o.source_id,
                    COUNT(DISTINCT o.id) AS listings,
                    COUNT(DISTINCT o.sim_number_id) AS unique_sims,
                    COUNT(DISTINCT CASE WHEN oc.id IS NOT NULL THEN o.id END) AS with_contact,
                    COUNT(oc.id) AS phone_records,
                    MAX(o.last_checked_at) AS latest_listing_at
             FROM offers o
             LEFT JOIN offer_contacts oc ON oc.offer_id = o.id
             GROUP BY o.source_id'
        )->fetchAll();
        $cache = [];
        foreach ($rows as $r) {
            $listings = (int) $r['listings'];
            $with = (int) $r['with_contact'];
            $cache[(int) $r['source_id']] = [
                'listings' => $listings,
                'unique_sims' => (int) $r['unique_sims'],
                'with_contact' => $with,
                'without_contact' => $listings - $with,
                'phone_records' => (int) $r['phone_records'],
                'latest_listing_at' => $r['latest_listing_at'] ?: null,
            ];
        }
        return $cache;
    }

    /**
     * @return array{sql:string,params:list<mixed>,joins:string}
     */
    private static function offerFilterSql(array $f, ?array $dates): array
    {
        $joins = ' JOIN sim_numbers sn ON sn.id = o.sim_number_id
                   JOIN sources src ON src.id = o.source_id ';
        $where = [self::panelVisibleSlugSql('src')];
        $params = [];

        if (($f['slug'] ?? '') !== '' && !self::isHiddenFromPanel((string) $f['slug'])) {
            $where[] = 'src.slug = ?';
            $params[] = $f['slug'];
        } elseif (($f['source'] ?? '') !== '' && ctype_digit((string) $f['source'])) {
            // Ignore filter if it points at a panel-hidden source.
            $chk = Database::pdo()->prepare('SELECT slug FROM sources WHERE id = ? LIMIT 1');
            $chk->execute([(int) $f['source']]);
            $srcSlug = (string) ($chk->fetchColumn() ?: '');
            if ($srcSlug !== '' && !self::isHiddenFromPanel($srcSlug)) {
                $where[] = 'o.source_id = ?';
                $params[] = (int) $f['source'];
            } elseif (self::isHiddenFromPanel($srcSlug)) {
                $where[] = '1=0';
            }
        }

        if (($f['q'] ?? '') !== '') {
            $digits = Digits::only(Digits::normalize($f['q']));
            $where[] = 'sn.number LIKE ?';
            $params[] = '%' . $digits . '%';
        }
        if (($f['code'] ?? '') !== '' && ctype_digit($f['code']) && strlen($f['code']) === 1) {
            $where[] = 'sn.code_digit = ?';
            $params[] = $f['code'];
        }
        if (($f['condition'] ?? '') !== '') {
            $where[] = 'o.condition_normalized = ?';
            $params[] = $f['condition'];
        }
        if (($f['city'] ?? '') !== '') {
            $where[] = 'o.city LIKE ?';
            $params[] = '%' . $f['city'] . '%';
        }
        if (($f['availability'] ?? '') !== '') {
            $where[] = 'o.availability = ?';
            $params[] = $f['availability'];
        }
        if (($f['price_type'] ?? '') !== '') {
            $where[] = 'o.price_type = ?';
            $params[] = $f['price_type'];
        }
        if (($f['min'] ?? '') !== '' && ctype_digit($f['min'])) {
            $where[] = 'o.price_amount >= ?';
            $params[] = (int) $f['min'];
        }
        if (($f['max'] ?? '') !== '' && ctype_digit($f['max'])) {
            $where[] = 'o.price_amount <= ?';
            $params[] = (int) $f['max'];
        }
        if ($dates && $dates['from']) {
            $where[] = 'o.last_checked_at >= ?';
            $params[] = $dates['from'];
        }
        if ($dates && $dates['to']) {
            $where[] = 'o.last_checked_at <= ?';
            $params[] = $dates['to'];
        }

        $hc = $f['has_contact'] ?? '';
        if (($f['phone'] ?? '') !== '') {
            $phoneDigits = Digits::only(Digits::normalize($f['phone']));
            $where[] = 'EXISTS (SELECT 1 FROM offer_contacts ocx WHERE ocx.offer_id = o.id AND ocx.phone LIKE ?)';
            $params[] = '%' . $phoneDigits . '%';
        } elseif ($hc === '1') {
            $where[] = 'EXISTS (SELECT 1 FROM offer_contacts ocx WHERE ocx.offer_id = o.id)';
        } elseif ($hc === '0') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM offer_contacts ocx WHERE ocx.offer_id = o.id)';
        }

        return [
            'sql' => implode(' AND ', $where),
            'params' => $params,
            'joins' => $joins,
        ];
    }

    private static function offerOrderSql(string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'o.price_amount IS NULL, o.price_amount ASC, o.id ASC',
            'price_desc' => 'o.price_amount IS NULL, o.price_amount DESC, o.id ASC',
            'checked_desc' => 'o.last_checked_at DESC, o.id DESC',
            'checked_asc' => 'o.last_checked_at ASC, o.id ASC',
            'sim_asc' => 'sn.number ASC, o.id ASC',
            'sim_desc' => 'sn.number DESC, o.id ASC',
            default => 'o.last_checked_at DESC, o.id DESC',
        };
    }

    public static function countOffers(array $f, ?array $dates): int
    {
        $built = self::offerFilterSql($f, $dates);
        $sql = 'SELECT COUNT(*) FROM offers o' . $built['joins'] . ' WHERE ' . $built['sql'];
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($built['params']);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function listOffers(array $f, ?array $dates, Pagination $pager): array
    {
        $built = self::offerFilterSql($f, $dates);
        $order = self::offerOrderSql((string) ($f['sort'] ?? ''));
        $sql = 'SELECT o.id, o.source_id, o.sim_number_id, o.external_id, o.canonical_url,
                       o.price_amount, o.price_type, o.condition_normalized, o.city, o.availability,
                       o.last_checked_at, o.last_seen_at, o.first_seen_at,
                       sn.number AS sim_number, sn.code_digit,
                       src.name AS source_name, src.slug AS source_slug
                FROM offers o'
            . $built['joins']
            . ' WHERE ' . $built['sql']
            . ' ORDER BY ' . $order
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($built['params']);
        return $stmt->fetchAll();
    }

    /**
     * Unique SIM market listing with aggregates.
     */
    public static function countSims(array $f, ?array $dates): int
    {
        $built = self::offerFilterSql($f, $dates);
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT sn.id FROM offers o'
            . $built['joins']
            . ' WHERE ' . $built['sql']
            . ' GROUP BY sn.id
                ) t';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($built['params']);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function listSims(array $f, ?array $dates, Pagination $pager): array
    {
        $built = self::offerFilterSql($f, $dates);
        $sort = (string) ($f['sort'] ?? '');
        $order = match ($sort) {
            'price_asc' => 'best_price IS NULL, best_price ASC, sn.number ASC',
            'price_desc' => 'best_price IS NULL, best_price DESC, sn.number ASC',
            'listings' => 'listing_count DESC, sn.number ASC',
            'checked_desc' => 'last_checked_at DESC, sn.number ASC',
            default => 'sn.number ASC',
        };

        $sql = 'SELECT sn.id, sn.number, sn.code_digit,
                       COUNT(DISTINCT o.id) AS listing_count,
                       COUNT(DISTINCT o.source_id) AS source_count,
                       MIN(o.price_amount) AS best_price,
                       MAX(o.last_checked_at) AS last_checked_at,
                       GROUP_CONCAT(DISTINCT src.name ORDER BY src.name SEPARATOR "، ") AS source_names,
                       GROUP_CONCAT(DISTINCT o.condition_normalized ORDER BY o.condition_normalized SEPARATOR ",") AS conditions,
                       GROUP_CONCAT(DISTINCT NULLIF(o.city, "") ORDER BY o.city SEPARATOR "، ") AS cities
                FROM offers o'
            . $built['joins']
            . ' WHERE ' . $built['sql']
            . ' GROUP BY sn.id, sn.number, sn.code_digit
                ORDER BY ' . $order
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($built['params']);
        $rows = $stmt->fetchAll();

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $phonesBySim = self::phonesBySimIds($ids);
        foreach ($rows as &$r) {
            $r['phones'] = $phonesBySim[(int) $r['id']] ?? [];
        }
        unset($r);

        return $rows;
    }

    /**
     * @param list<int> $simIds
     * @return array<int, list<string>>
     */
    public static function phonesBySimIds(array $simIds): array
    {
        if (!$simIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($simIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT o.sim_number_id, oc.phone
             FROM offer_contacts oc
             INNER JOIN offers o ON o.id = oc.offer_id
             WHERE o.sim_number_id IN ($in)
             ORDER BY oc.phone"
        );
        $stmt->execute($simIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int) $row['sim_number_id'];
            $phone = (string) $row['phone'];
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }
            if (!in_array($phone, $out[$sid], true)) {
                $out[$sid][] = $phone;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $offerIds
     * @return array<int, list<array<string,mixed>>>
     */
    public static function contactsByOfferIds(array $offerIds): array
    {
        if (!$offerIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($offerIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM offer_contacts WHERE offer_id IN ($in) ORDER BY scope, phone"
        );
        $stmt->execute(array_values($offerIds));
        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $out[(int) $c['offer_id']][] = $c;
        }
        return $out;
    }

    public static function countContacts(array $f, ?array $dates): int
    {
        $pdo = Database::pdo();
        $where = [self::panelVisibleSlugSql('src')];
        $params = [];
        $joins = ' FROM offer_contacts oc
                   INNER JOIN offers o ON o.id = oc.offer_id
                   INNER JOIN sim_numbers sn ON sn.id = o.sim_number_id
                   INNER JOIN sources src ON src.id = o.source_id ';

        if (($f['phone'] ?? '') !== '') {
            $where[] = 'oc.phone LIKE ?';
            $params[] = '%' . Digits::only(Digits::normalize($f['phone'])) . '%';
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = 'sn.number LIKE ?';
            $params[] = '%' . Digits::only(Digits::normalize($f['q'])) . '%';
        }
        if (($f['source'] ?? '') !== '' && ctype_digit((string) $f['source'])) {
            $where[] = 'o.source_id = ?';
            $params[] = (int) $f['source'];
        }
        if (($f['slug'] ?? '') !== '' && !self::isHiddenFromPanel((string) $f['slug'])) {
            $where[] = 'src.slug = ?';
            $params[] = $f['slug'];
        } elseif (($f['slug'] ?? '') !== '' && self::isHiddenFromPanel((string) $f['slug'])) {
            $where[] = '1=0';
        }
        if (($f['contact_scope'] ?? '') !== '') {
            $where[] = 'oc.scope = ?';
            $params[] = $f['contact_scope'];
        }
        if ($dates && $dates['from']) {
            $where[] = 'o.last_checked_at >= ?';
            $params[] = $dates['from'];
        }
        if ($dates && $dates['to']) {
            $where[] = 'o.last_checked_at <= ?';
            $params[] = $dates['to'];
        }

        $sql = 'SELECT COUNT(*)' . $joins . ' WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function listContacts(array $f, ?array $dates, Pagination $pager): array
    {
        $where = [self::panelVisibleSlugSql('src')];
        $params = [];
        $joins = ' FROM offer_contacts oc
                   INNER JOIN offers o ON o.id = oc.offer_id
                   INNER JOIN sim_numbers sn ON sn.id = o.sim_number_id
                   INNER JOIN sources src ON src.id = o.source_id ';

        if (($f['phone'] ?? '') !== '') {
            $where[] = 'oc.phone LIKE ?';
            $params[] = '%' . Digits::only(Digits::normalize($f['phone'])) . '%';
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = 'sn.number LIKE ?';
            $params[] = '%' . Digits::only(Digits::normalize($f['q'])) . '%';
        }
        if (($f['source'] ?? '') !== '' && ctype_digit((string) $f['source'])) {
            $where[] = 'o.source_id = ?';
            $params[] = (int) $f['source'];
        }
        if (($f['slug'] ?? '') !== '' && !self::isHiddenFromPanel((string) $f['slug'])) {
            $where[] = 'src.slug = ?';
            $params[] = $f['slug'];
        } elseif (($f['slug'] ?? '') !== '' && self::isHiddenFromPanel((string) $f['slug'])) {
            $where[] = '1=0';
        }
        if (($f['contact_scope'] ?? '') !== '') {
            $where[] = 'oc.scope = ?';
            $params[] = $f['contact_scope'];
        }
        if ($dates && $dates['from']) {
            $where[] = 'o.last_checked_at >= ?';
            $params[] = $dates['from'];
        }
        if ($dates && $dates['to']) {
            $where[] = 'o.last_checked_at <= ?';
            $params[] = $dates['to'];
        }

        $sort = (string) ($f['sort'] ?? '');
        $order = match ($sort) {
            'phone_asc' => 'oc.phone ASC, oc.id ASC',
            'sim_asc' => 'sn.number ASC, oc.id ASC',
            'price_asc' => 'o.price_amount IS NULL, o.price_amount ASC, oc.id ASC',
            'price_desc' => 'o.price_amount IS NULL, o.price_amount DESC, oc.id ASC',
            default => 'oc.id DESC',
        };

        $sql = 'SELECT oc.id, oc.phone, oc.phone_type, oc.scope, oc.label, oc.offer_id,
                       sn.number AS sim_number, o.price_amount, o.price_type, o.canonical_url,
                       o.external_id, o.last_checked_at, src.name AS source_name, src.slug AS source_slug'
            . $joins
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $order
            . ' LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function simByNumber(string $number): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sim_numbers WHERE number = ? LIMIT 1');
        $stmt->execute([$number]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function offersForSim(int $simId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.*, sn.number AS sim_number, sn.code_digit,
                    src.name AS source_name, src.slug AS source_slug
             FROM offers o
             JOIN sim_numbers sn ON sn.id = o.sim_number_id
             JOIN sources src ON src.id = o.source_id
             WHERE o.sim_number_id = ?
             ORDER BY o.price_amount IS NULL, o.price_amount ASC, src.name ASC, o.id ASC'
        );
        $stmt->execute([$simId]);
        return $stmt->fetchAll();
    }
}
