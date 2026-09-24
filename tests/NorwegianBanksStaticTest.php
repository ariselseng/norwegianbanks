<?php

namespace Ariselseng\NorwegianBanks\Tests;

use Ariselseng\NorwegianBanks\NorwegianBanks;
use Ariselseng\NorwegianBanks\NorwegianBanksStatic;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class NorwegianBanksStaticTest extends TestCase
{
    // A fresh process, so nothing has loaded NorwegianBanks.php before the facade is used.
    #[RunInSeparateProcess]
    public function testCanBeUsedBeforeNorwegianBanksIsLoaded()
    {
        $this->assertFalse(class_exists(NorwegianBanks::class, false));
        $this->assertTrue(class_exists(NorwegianBanksStatic::class));
        $this->assertFalse(class_exists(NorwegianBanks::class, false));

        $dir = sys_get_temp_dir() . '/norwegianbanks-test-' . bin2hex(random_bytes(6));
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], SheetFixture::xlsx())]))]);
        try {
            NorwegianBanksStatic::setInstance(new NorwegianBanks($dir, $client));
            $this->assertSame('DNBANOKK', NorwegianBanksStatic::getBankByAccountNumber('1594 22 87248')->bankCode);
            $this->assertTrue(NorwegianBanksStatic::validateAccountNumber('3000.27.79419'));
        } finally {
            NorwegianBanksStatic::setInstance(null);
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }
}
