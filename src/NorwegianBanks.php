<?php

namespace Ariselseng\NorwegianBanks;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class NorwegianBanks
{
    private const XLSX_URL = 'https://www.bits.no/document/iban/';
    private const XLSX_FILE = 'norwegian-iban-bic-table.xlsx';
    private const CHECKED_FILE = 'norwegian-iban-bic-table.checked';
    private const CACHE_FILE = 'banks.json';
    private const CACHE_VERSION = 1;
    private const CHECK_INTERVAL = 86400;
    private const RETRY_INTERVAL = 3600;
    private const MIN_BANKS = 50;
    private const MIN_PREFIXES = 1000;
    private const NO_BIC = 'n/a';

    private ClientInterface $httpClient;
    private string $dir;

    /** @var array<string, NorwegianBank> */
    private array $banks = [];

    /** @var array<string, string> prefix => key in $banks */
    private array $prefixToBankKey = [];

    /**
     * @param string|null $cacheDir Directory for the downloaded table and the parsed cache. Defaults to a directory in
     *                              sys_get_temp_dir() that only the current user can write to.
     * @param ClientInterface|null $httpClient Client used to download the table from bits.no.
     */
    public function __construct(?string $cacheDir = null, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 10, 'connect_timeout' => 5]);

        $ephemeral = false;
        if ($cacheDir !== null) {
            $this->dir = rtrim($cacheDir, '/\\');
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Could not create cache directory {$this->dir}");
            }
        } else {
            [$this->dir, $ephemeral] = self::privateTempDir();
        }

        try {
            $this->load();
        } finally {
            if ($ephemeral) {
                self::removeDir($this->dir);
            }
        }
    }

    private function load(): void
    {
        $banks = $this->refresh();
        if ($banks === null) {
            $xlsx = $this->path(self::XLSX_FILE);
            $source = md5_file($xlsx);
            $banks = $this->readCache($source);
            if ($banks === null) {
                $banks = self::parse($xlsx);
                $this->writeCache($source, $banks);
            }
        }

        foreach ($banks as $key => $bank) {
            $this->banks[$key] = new NorwegianBank($bank['bic'] ?? self::NO_BIC, $bank['name'], $bank['prefixes']);
            foreach ($bank['prefixes'] as $prefix) {
                $this->prefixToBankKey[$prefix] = $key;
            }
        }
    }

    /**
     * Makes sure there is a reasonably fresh copy of the table in the cache directory.
     *
     * @return array|null The parsed table when a new file was downloaded, so it isn't parsed twice.
     */
    private function refresh(): ?array
    {
        $xlsx = $this->path(self::XLSX_FILE);
        clearstatcache();
        $haveFile = is_file($xlsx);
        if ($haveFile && ($this->lastChecked() > time() - self::CHECK_INTERVAL || !is_writable($this->dir))) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::XLSX_URL, [
                'headers' => $haveFile ? ['If-Modified-Since' => gmdate('D, d M Y H:i:s T', filemtime($xlsx))] : [],
            ]);
            if ($response->getStatusCode() === 304) {
                $this->markChecked(time());
                return null;
            }
            if ($response->getStatusCode() !== 200) {
                throw new \UnexpectedValueException('Unexpected HTTP status ' . $response->getStatusCode() . ' from ' . self::XLSX_URL);
            }
            $banks = $this->store((string)$response->getBody(), $response->getHeaderLine('Last-Modified'));
            $this->markChecked(time());
            return $banks;
        } catch (\Exception $e) {
            if (!$haveFile) {
                throw $e;
            }
            // Keep using the copy we have, and try again later.
            $this->markChecked(time() - self::CHECK_INTERVAL + self::RETRY_INTERVAL);
            return null;
        }
    }

    /**
     * Validates a downloaded table and atomically replaces the local copy with it.
     *
     * @return array|null The parsed table, or null if the file is unchanged.
     */
    private function store(string $body, string $lastModified): ?array
    {
        $xlsx = $this->path(self::XLSX_FILE);
        if (is_file($xlsx) && md5($body) === md5_file($xlsx)) {
            return null;
        }
        if (!str_starts_with($body, "PK\x03\x04")) {
            throw new \UnexpectedValueException('The file downloaded from ' . self::XLSX_URL . ' is not an xlsx file');
        }

        $tmp = $xlsx . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (@file_put_contents($tmp, $body) !== strlen($body)) {
                throw new \RuntimeException("Could not write $tmp");
            }
            $banks = self::parse($tmp);
            // The server's own Last-Modified makes a good If-Modified-Since for the next check.
            @touch($tmp, strtotime($lastModified) ?: time());
            if (!@rename($tmp, $xlsx)) {
                throw new \RuntimeException("Could not replace $xlsx");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->writeCache(md5($body), $banks);
        return $banks;
    }

    /**
     * @return array Banks keyed by BIC ('n/a:<name>' for banks without one), each as ['bic', 'name', 'prefixes'].
     */
    private static function parse(string $xlsx): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($xlsx)->getSheet(0);
        // Only columns A-C are used, and the sheet's used range is far wider than that.
        $rows = $sheet->rangeToArray('A1:C' . $sheet->getHighestDataRow('A'), null, false, false, false);

        $banks = [];
        $seen = [];
        foreach ($rows as [$prefix, $bic, $name]) {
            // Prefixes are text in the sheet, but a numeric cell would have lost its leading zero.
            $prefix = is_int($prefix) || is_float($prefix) ? sprintf('%04d', $prefix) : trim((string)$prefix);
            $bic = trim((string)$bic);
            $name = trim((string)$name);
            // Skips the header, notes, blank rows and duplicates.
            if (!preg_match('/^\d{4}$/', $prefix) || $name === '' || isset($seen[$prefix])) {
                continue;
            }
            $seen[$prefix] = true;
            $key = $bic === '' ? self::NO_BIC . ':' . $name : $bic;
            $banks[$key] ??= ['bic' => $bic === '' ? null : $bic, 'name' => $name, 'prefixes' => []];
            $banks[$key]['prefixes'][] = $prefix;
        }

        if (count($banks) < self::MIN_BANKS || count($seen) < self::MIN_PREFIXES) {
            throw new \UnexpectedValueException(sprintf(
                '%s does not look like the IBAN/BIC table from bits.no (%d banks, %d prefixes)',
                $xlsx,
                count($banks),
                count($seen)
            ));
        }

        return $banks;
    }

    private function readCache(string $source): ?array
    {
        $json = @file_get_contents($this->path(self::CACHE_FILE));
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)
            || ($data['version'] ?? null) !== self::CACHE_VERSION
            || ($data['source'] ?? null) !== $source
            || !is_array($data['banks'] ?? null)) {
            return null;
        }

        foreach ($data['banks'] as $bank) {
            $valid = is_array($bank)
                && is_string($bank['name'] ?? null)
                && (!isset($bank['bic']) || is_string($bank['bic']))
                && is_array($bank['prefixes'] ?? null)
                && array_filter($bank['prefixes'], 'is_string') === $bank['prefixes'];
            if (!$valid) {
                return null;
            }
        }

        return $data['banks'];
    }

    private function writeCache(string $source, array $banks): void
    {
        $json = json_encode(['version' => self::CACHE_VERSION, 'source' => $source, 'banks' => $banks], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $cacheFile = $this->path(self::CACHE_FILE);
        $tmp = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }

    private function lastChecked(): int
    {
        return @filemtime($this->path(self::CHECKED_FILE)) ?: 0;
    }

    private function markChecked(int $time): void
    {
        @touch($this->path(self::CHECKED_FILE), $time);
    }

    private function path(string $file): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Anything found in a temp directory that someone else controls could have been planted there, so if the
     * directory for the current user isn't private, a throwaway directory is used instead.
     *
     * @return array{string, bool} The directory, and whether it must be removed after use.
     */
    private static function privateTempDir(): array
    {
        $uid = self::currentUid();
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'norwegianbanks' . ($uid === null ? '' : "-$uid");
        if ((is_dir($dir) || @mkdir($dir, 0700) || is_dir($dir)) && self::isPrivateDir($dir, $uid)) {
            return [$dir, false];
        }

        $dir .= '-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new \RuntimeException("Could not create temporary directory $dir");
        }
        return [$dir, true];
    }

    private static function currentUid(): ?int
    {
        static $uid = false;
        if ($uid !== false) {
            return $uid;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $uid = null;
        } elseif (function_exists('posix_geteuid')) {
            $uid = posix_geteuid();
        } else {
            // Without ext-posix, the owner of a file we just created tells us who we are.
            $probe = @tempnam(sys_get_temp_dir(), 'norwegianbanks');
            $owner = $probe === false ? false : @fileowner($probe);
            if ($probe !== false) {
                @unlink($probe);
            }
            $uid = $owner === false ? null : $owner;
        }

        return $uid;
    }

    private static function isPrivateDir(string $dir, ?int $uid): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        clearstatcache(true, $dir);
        $perms = @fileperms($dir);
        return $uid !== null
            && !is_link($dir)
            && @fileowner($dir) === $uid
            && $perms !== false
            && ($perms & 0022) === 0;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private static function onlyDigits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * @param string $prefix
     * @return string|null The BIC, 'n/a' if the bank has none, or null if the prefix is unknown.
     */
    public function getBankCodeByPrefix(string $prefix): ?string
    {
        $key = $this->prefixToBankKey[$prefix] ?? null;
        return $key === null ? null : $this->banks[$key]->bankCode;
    }

    /**
     * @param string $account
     * @return NorwegianBank|null
     */
    public function getBankByAccountNumber(string $account): ?NorwegianBank
    {
        $key = $this->prefixToBankKey[substr(self::onlyDigits($account), 0, 4)] ?? null;
        return $key === null ? null : $this->banks[$key];
    }

    /**
     * @param string $unformattedAccount
     * @param string $delimiter
     * @return string
     */
    public function getFormattedAccountNumber(string $unformattedAccount, string $delimiter = '.'): string
    {
        $onlyDigits = self::onlyDigits($unformattedAccount);
        return substr($onlyDigits, 0, 4) . $delimiter . substr($onlyDigits, 4, 2) . $delimiter . substr($onlyDigits, 6);
    }

    /**
     * @param string $account
     * @param bool $validateBankPrefix
     * @return bool
     */
    public function validateAccountNumber(string $account, bool $validateBankPrefix = true): bool
    {

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $onlyDigits = self::onlyDigits($account);

        if (strlen($onlyDigits) !== 11) {
            return false;
        }

        $checkDigit = (int)substr($onlyDigits, -1, 1);

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int)substr($onlyDigits, $i, 1) * $weights[$i];
        }

        $remainder = $sum % 11;

        if ($remainder === 0) {
            $checkDigitFromRemainder = $remainder;
        } else {
            $checkDigitFromRemainder = 11 - $remainder;
        }

        if ($checkDigit !== $checkDigitFromRemainder) {
            return false;
        }

        if (!$validateBankPrefix) {
            return true;
        }

        return !is_null($this->getBankByAccountNumber($onlyDigits));
    }

    /**
     * @return string[]
     */
    public function getAllPrefixes(): array
    {
        return array_map('strval', array_keys($this->prefixToBankKey));
    }

    /**
     * @return NorwegianBank[] Keyed by BIC ('n/a:<name>' for banks without one).
     */
    public function getAllBanks(): array
    {
        return $this->banks;
    }
}
