<?php

namespace Tests\Unit;

use App\Scraping\Support\PriceNormalizer;
use PHPUnit\Framework\TestCase;

class PriceNormalizerTest extends TestCase
{
    public function test_toman_fixed_price(): void
    {
        $p = PriceNormalizer::parse('7,500,000,000 تومان');
        $this->assertSame(7500000000, $p['amount']);
        $this->assertSame('fixed', $p['type']);
        $this->assertSame('toman', $p['unit']);
    }

    public function test_rial_conversion(): void
    {
        $p = PriceNormalizer::parse('75000000000 ریال');
        $this->assertSame(7500000000, $p['amount']);
        $this->assertSame('toman', $p['unit']);
    }

    public function test_installment_not_treated_as_asking_price(): void
    {
        $p = PriceNormalizer::parse('پیش پرداخت 50,000,000 تومان ماهانه');
        $this->assertNull($p['amount']);
        $this->assertSame('installment', $p['type']);
    }

    public function test_cash_price_in_installment_context(): void
    {
        $p = PriceNormalizer::parse('نقدی: 660,000,000 تومان — اقساط موجود');
        $this->assertSame(660000000, $p['amount']);
        $this->assertSame('fixed', $p['type']);
    }

    public function test_call_and_negotiable(): void
    {
        $this->assertSame('call', PriceNormalizer::parse('تماس بگیرید')['type']);
        $this->assertSame('negotiable', PriceNormalizer::parse('قیمت توافقی')['type']);
    }
}
