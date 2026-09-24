<?php

namespace Ariselseng\NorwegianBanks;


class NorwegianBank
{
    public string $bankCode;
    public string $bankName;
    /** @var string[] */
    public array $prefixes;

    /**
     * @param string $bankCode BIC, or 'n/a' if the bank has none
     * @param string $bankName
     * @param string[] $prefixes
     */
    public function __construct(string $bankCode, string $bankName, array $prefixes = [])
    {
        $this->bankCode = $bankCode;
        $this->bankName = $bankName;
        $this->prefixes = $prefixes;
    }
    public function addPrefix(string $prefix): void
    {
        $this->prefixes[] = $prefix;
    }
}
