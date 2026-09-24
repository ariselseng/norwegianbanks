<?php

namespace Ariselseng\NorwegianBanks;

/**
 * Static access to one shared NorwegianBanks instance, created on first use.
 *
 * @method static string|null getBankCodeByPrefix(string $prefix)
 * @method static NorwegianBank|null getBankByAccountNumber(string $account)
 * @method static string getFormattedAccountNumber(string $unformattedAccount, string $delimiter = '.')
 * @method static bool validateAccountNumber(string $account, bool $validateBankPrefix = true)
 * @method static string[] getAllPrefixes()
 * @method static NorwegianBank[] getAllBanks()
 */
class NorwegianBanksStatic
{
    private static ?NorwegianBanks $instance = null;

    /**
     * Use a configured instance (e.g. with a custom cache directory), or null to go back to the default.
     */
    public static function setInstance(?NorwegianBanks $instance): void
    {
        self::$instance = $instance;
    }

    public static function __callStatic($method, $args)
    {
        self::$instance ??= new NorwegianBanks();
        return self::$instance->$method(...$args);
    }
}
