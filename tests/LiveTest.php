<?php

namespace Ariselseng\NorwegianBanks\Tests;

use Ariselseng\NorwegianBanks\NorwegianBanks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real table from bits.no. Excluded by default; run with `vendor/bin/phpunit --group live`.
 */
#[Group('live')]
class LiveTest extends TestCase
{
    public function testRealTable()
    {
        $norwegianBanks = new NorwegianBanks();

        $this->assertSame('DNBANOKK', $norwegianBanks->getBankCodeByPrefix('1594'));
        $this->assertSame('NDEANOKK', $norwegianBanks->getBankCodeByPrefix('6105'));
        $this->assertSame('SPSONO22', $norwegianBanks->getBankCodeByPrefix('3000'));
        $this->assertTrue($norwegianBanks->validateAccountNumber('1594 22 87248'));
        $this->assertGreaterThan(1000, count($norwegianBanks->getAllPrefixes()));
    }
}
