<?php

namespace Tests\Unit;

use App\Scraping\Support\DigitNormalizer;
use PHPUnit\Framework\TestCase;

class DigitNormalizerTest extends TestCase
{
    public function test_persian_and_arabic_digits(): void
    {
        $this->assertSame('09121234567', DigitNormalizer::extractSim0912('۰۹۱۲۱۲۳۴۵۶۷'));
        $this->assertSame('09121234567', DigitNormalizer::extractSim0912('٠٩١٢١٢٣٤٥٦٧'));
    }

    public function test_international_notation(): void
    {
        $this->assertSame('09121234567', DigitNormalizer::extractSim0912('+989121234567'));
        $this->assertSame('09121234567', DigitNormalizer::extractSim0912('00989121234567'));
        $this->assertSame('09121234567', DigitNormalizer::extractSim0912('989121234567'));
    }

    public function test_separators_and_spaced_digits(): void
    {
        $this->assertSame('09121275000', DigitNormalizer::extractSim0912('0912 127 5000'));
        $this->assertSame('09121275000', DigitNormalizer::extractSim0912('0 9 1 2 1 2 7 5 0 0 0'));
        $this->assertSame('09121008487', DigitNormalizer::extractSim0912('0912-100-84-87'));
    }

    public function test_rejects_masked_incomplete(): void
    {
        $this->assertNull(DigitNormalizer::extractSim0912('0912***4567'));
        $this->assertNull(DigitNormalizer::extractSim0912('0912xxxx567'));
        $this->assertNull(DigitNormalizer::extractSim0912('0912123'));
    }

    public function test_only_0912_accepted(): void
    {
        $this->assertNull(DigitNormalizer::extractSim0912('09191234567'));
        $this->assertTrue(DigitNormalizer::isValid0912('09121234567'));
        $this->assertSame('1', DigitNormalizer::codeDigit('09121234567'));
    }
}
