<?php

declare(strict_types=1);

final class Pagination
{
    public const ALLOWED_PER_PAGE = [25, 50, 100];

    public int $page;
    public int $perPage;
    public int $total;
    public int $totalPages;
    public int $offset;

    public function __construct(int $page, int $perPage, int $total)
    {
        $this->perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : 50;
        $this->total = max(0, $total);
        $this->totalPages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $this->totalPages));
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public static function fromRequest(int $total, int $defaultPerPage = 50): self
    {
        $page = max(1, (int) (get_q('page', '1') ?? '1'));
        $per = (int) (get_q('per', (string) $defaultPerPage) ?? (string) $defaultPerPage);
        return new self($page, $per, $total);
    }

    /** @param array<string, scalar|null> $baseQuery */
    public function url(array $baseQuery, int $page): string
    {
        $q = $baseQuery;
        $q['page'] = $page;
        $q['per'] = $this->perPage;
        return '/?' . http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
    }

    public function fromItem(): int
    {
        if ($this->total === 0) {
            return 0;
        }
        return $this->offset + 1;
    }

    public function toItem(): int
    {
        return min($this->total, $this->offset + $this->perPage);
    }
}
