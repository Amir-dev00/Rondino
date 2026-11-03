<?php

namespace Tests\Unit;

use App\Scraping\Support\ConditionNormalizer;
use PHPUnit\Framework\TestCase;

class ConditionNormalizerTest extends TestCase
{
    public function test_factory_sealed_only_from_pack_wording(): void
    {
        $this->assertSame('factory_sealed', ConditionNormalizer::normalize('صفر پک')['normalized']);
        $this->assertSame('new_unspecified', ConditionNormalizer::normalize('صفر')['normalized']);
    }

    public function test_like_new_and_used(): void
    {
        $this->assertSame('like_new', ConditionNormalizer::normalize('در حد صفر')['normalized']);
        $this->assertSame('like_new', ConditionNormalizer::normalize('در حد نو')['normalized']);
        $this->assertSame('used', ConditionNormalizer::normalize('کارکرده')['normalized']);
    }

    public function test_preserves_raw(): void
    {
        $r = ConditionNormalizer::normalize('کارکرده تمیز');
        $this->assertSame('کارکرده تمیز', $r['raw']);
        $this->assertSame('used', $r['normalized']);
    }
}
