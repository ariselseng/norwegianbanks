<?php

namespace Ariselseng\NorwegianBanks\Tests;

use Ariselseng\NorwegianBanks\NorwegianBank;
use Ariselseng\NorwegianBanks\NorwegianBanks;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class NorwegianBanksTest extends TestCase
{
    private const LAST_MODIFIED = 'Tue, 08 Sep 2026 10:00:00 GMT';

    private static string $xlsx;
    private string $dir;
    private array $requests = [];
    private NorwegianBanks $norwegianBanks;
    protected string $notRealAccountNumber = '6199.56.78909';
    protected string $notRealAccountNumberWithSpaces = '6199 56 78909';
    protected string $notRealAccountNumberUnformatted = '61995678909';
    protected string $accountNumberWithZeroCheckDigit = '0101.01.04900';

    protected array $accounts = [
        [
            'bankCode' => 'DNBANOKK',
            'number' => '1594 22 87248'
        ],
        [
            'bankCode' => 'NDEANOKK',
            'number' => '61050659274'
        ],
        [
            'bankCode' => 'SPSONO22',
            'number' => '3000.27.79419'
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$xlsx = SheetFixture::xlsx();
    }

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/norwegianbanks-test-' . bin2hex(random_bytes(6));
        $this->norwegianBanks = $this->create(new Response(200, ['Last-Modified' => self::LAST_MODIFIED], self::$xlsx));
    }

    public function tearDown(): void
    {
        $this->removeCacheDir();
    }

    private function removeCacheDir(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * Creates an instance on the test's cache directory whose HTTP requests are answered by $responses, in order.
     */
    private function create(...$responses): NorwegianBanks
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(function (callable $next) {
            return function ($request, array $options) use ($next) {
                $this->requests[] = $request;
                return $next($request, $options);
            };
        });
        return new NorwegianBanks($this->dir, new Client(['handler' => $handler]));
    }

    private function expireLastCheck(): void
    {
        touch($this->dir . '/norwegian-iban-bic-table.checked', time() - 2 * 86400);
    }

    public function testGetFormattedAccountNumber()
    {
        $this->assertEquals($this->notRealAccountNumber, $this->norwegianBanks->getFormattedAccountNumber($this->notRealAccountNumberUnformatted));
        $this->assertEquals($this->notRealAccountNumberWithSpaces, $this->norwegianBanks->getFormattedAccountNumber($this->notRealAccountNumberUnformatted, ' '));
    }

    public function testGetBankCodeByPrefix()
    {
        foreach ($this->accounts as $account) {
            $this->assertEquals($account['bankCode'], $this->norwegianBanks->getBankCodeByPrefix(substr($account['number'], 0, 4)));
        }
        $this->assertNull($this->norwegianBanks->getBankCodeByPrefix('0000'));
        $this->assertSame('n/a', $this->norwegianBanks->getBankCodeByPrefix('4213'));
    }

    public function testGetBankByAccountNumber()
    {

        foreach ($this->accounts as $account) {
            $this->assertEquals($account['bankCode'], $this->norwegianBanks->getBankByAccountNumber($account['number'])->bankCode);
        }

        $this->assertNull($this->norwegianBanks->getBankByAccountNumber($this->notRealAccountNumber));
    }

    public function testGetBankByAccountNumberIgnoresFormatting()
    {
        foreach ([' 1594 22 87248', "\t15942287248", '(1594) 22 87248', 'Konto: 1594.22.87248'] as $account) {
            $this->assertTrue($this->norwegianBanks->validateAccountNumber($account), $account);
            $this->assertSame('DNBANOKK', $this->norwegianBanks->getBankByAccountNumber($account)?->bankCode, $account);
        }
    }

    public function testBanksWithoutBicAreKeptApart()
    {
        $this->assertSame('Bank uten BIC A', $this->norwegianBanks->getBankByAccountNumber('42130000000')->bankName);
        $this->assertSame('Bank uten BIC B', $this->norwegianBanks->getBankByAccountNumber('99600000000')->bankName);
        $this->assertSame('n/a', $this->norwegianBanks->getBankByAccountNumber('99600000000')->bankCode);
    }

    public function testValidate()
    {

        foreach ($this->accounts as $account) {
            $this->assertTrue($this->norwegianBanks->validateAccountNumber($account['number']));
        }

        $this->assertFalse($this->norwegianBanks->validateAccountNumber($this->notRealAccountNumber));
        $this->assertTrue($this->norwegianBanks->validateAccountNumber($this->notRealAccountNumber, false));
        $this->assertTrue($this->norwegianBanks->validateAccountNumber($this->accountNumberWithZeroCheckDigit, false));

    }

    public function testValidateRejectsWrongCheckDigitAndLength()
    {
        $this->assertFalse($this->norwegianBanks->validateAccountNumber('1594 22 87249'));
        $this->assertFalse($this->norwegianBanks->validateAccountNumber('1594 22 8724', false));
        $this->assertFalse($this->norwegianBanks->validateAccountNumber('1594 22 872480', false));
        $this->assertFalse($this->norwegianBanks->validateAccountNumber('', false));
        // The weighted sum of 1594228723 leaves remainder 1, so no check digit can make it valid.
        for ($checkDigit = 0; $checkDigit <= 9; $checkDigit++) {
            $this->assertFalse($this->norwegianBanks->validateAccountNumber("1594228723$checkDigit", false));
        }
    }

    public function testGetAllPrefixes()
    {
        $prefixes = $this->norwegianBanks->getAllPrefixes();
        $this->assertContainsOnlyString($prefixes);
        $this->assertCount(8 + 60 * SheetFixture::BULK_PREFIXES_PER_BANK, $prefixes);
        $this->assertNotContains('Bank identifier', $prefixes);
        $this->assertNotContains('8888', $prefixes);
        $this->assertContains('1594', $prefixes);
        $this->assertContains('0530', $prefixes);
        $this->assertContains('6105', $prefixes);
    }

    public function testGetAllBanks()
    {
        $banks = $this->norwegianBanks->getAllBanks();
        $this->assertContainsOnlyInstancesOf(NorwegianBank::class, $banks);
        $this->assertArrayHasKey($this->accounts[0]['bankCode'], $banks);
        $this->assertSame('DNB Bank ASA', $banks['DNBANOKK']->bankName);
        $this->assertSame(['1594', '1600', '0530'], $banks['DNBANOKK']->prefixes);
        $this->assertSame('SpareBank 1 Nordmøre', $banks['NORVNO21']->bankName);
    }

    public function testUsesCacheWithoutDownloadingAgain()
    {
        $this->requests = [];
        $cached = $this->create();

        $this->assertCount(0, $this->requests);
        $this->assertEquals($this->norwegianBanks->getAllBanks(), $cached->getAllBanks());
        $this->assertSame($this->norwegianBanks->getAllPrefixes(), $cached->getAllPrefixes());
    }

    public function testIgnoresCorruptCache()
    {
        $cacheFile = $this->dir . '/banks.json';
        file_put_contents($cacheFile, substr(file_get_contents($cacheFile), 0, 100));
        $this->requests = [];

        $norwegianBanks = $this->create();

        $this->assertCount(0, $this->requests);
        $this->assertTrue($norwegianBanks->validateAccountNumber('1594 22 87248'));
        $this->assertIsArray(json_decode(file_get_contents($cacheFile), true));
    }

    public function testRevalidatesWithIfModifiedSince()
    {
        $this->expireLastCheck();
        $this->requests = [];

        $norwegianBanks = $this->create(new Response(304));

        $this->assertCount(1, $this->requests);
        $this->assertSame(self::LAST_MODIFIED, $this->requests[0]->getHeaderLine('If-Modified-Since'));
        $this->assertTrue($norwegianBanks->validateAccountNumber('1594 22 87248'));
    }

    public function testKeepsLocalCopyWhenDownloadIsNotXlsx()
    {
        $xlsxFile = $this->dir . '/norwegian-iban-bic-table.xlsx';
        $before = md5_file($xlsxFile);
        $this->expireLastCheck();

        $norwegianBanks = $this->create(new Response(200, [], '<!doctype html><title>Maintenance</title>'));

        $this->assertSame($before, md5_file($xlsxFile));
        $this->assertTrue($norwegianBanks->validateAccountNumber('1594 22 87248'));
    }

    public function testKeepsLocalCopyWhenServerIsUnreachable()
    {
        $this->expireLastCheck();
        $this->requests = [];

        $norwegianBanks = $this->create(new ConnectException('Connection refused', new Request('GET', 'https://www.bits.no/document/iban/')));
        $this->assertTrue($norwegianBanks->validateAccountNumber('1594 22 87248'));

        // The failed check is remembered, so the next instance doesn't try again right away.
        $this->create();
        $this->assertCount(1, $this->requests);
    }

    public function testThrowsWithoutLocalCopyWhenServerIsUnreachable()
    {
        $this->removeCacheDir();

        $this->expectException(ConnectException::class);
        $this->create(new ConnectException('Connection refused', new Request('GET', 'https://www.bits.no/document/iban/')));
    }

    public function testRejectsTableThatDoesNotLookRight()
    {
        $this->removeCacheDir();

        $this->expectException(\UnexpectedValueException::class);
        $this->create(new Response(200, [], SheetFixture::xlsx(2)));
    }
}
