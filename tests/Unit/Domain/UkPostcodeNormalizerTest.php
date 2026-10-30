<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Taxi\Services\UkPostcodeNormalizer;
use PHPUnit\Framework\TestCase;

class UkPostcodeNormalizerTest extends TestCase
{
    private UkPostcodeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UkPostcodeNormalizer;
    }

    public function test_outcode_from_full_postcode(): void
    {
        $this->assertSame('M1', $this->normalizer->outcode('M1 1AE'));
        $this->assertSame('M14', $this->normalizer->outcode('m14 4tp'));
        $this->assertSame('SW1A', $this->normalizer->outcode('SW1A 1AA'));
    }

    public function test_outcode_from_outcode_only(): void
    {
        $this->assertSame('M1', $this->normalizer->outcode('M1'));
        $this->assertSame('M90', $this->normalizer->outcode('m90'));
    }
}
