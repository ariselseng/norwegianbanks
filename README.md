# norwegianbanks
A PHP library for validation norwegian bank account numbers

Uses data from https://www.bits.no

## Usage

```php
use Ariselseng\NorwegianBanks\NorwegianBanks;

$banks = new NorwegianBanks();

$banks->validateAccountNumber('1594 22 87248');          // true (checksum and known bank prefix)
$banks->validateAccountNumber('1594 22 87248', false);   // checksum only
$banks->getBankByAccountNumber('1594.22.87248');         // NorwegianBank { bankCode: 'DNBANOKK', bankName: 'DNB Bank ASA', prefixes: [...] }
$banks->getBankCodeByPrefix('1594');                     // 'DNBANOKK'
$banks->getFormattedAccountNumber('15942287248');        // '1594.22.87248'
```

`NorwegianBanksStatic` offers the same methods statically, backed by one shared instance.

## Data and caching

The constructor downloads the IBAN/BIC table from bits.no the first time, then checks for a new version at most once
a day (a conditional request, so an unchanged table is not downloaded again). If bits.no can't be reached or returns
something that isn't a valid table, the local copy keeps being used and the check is retried an hour later.

The table and a parsed cache are kept in a directory that only the current user can write to, by default
`sys_get_temp_dir()/norwegianbanks-<uid>`. Both can be configured:

```php
$banks = new NorwegianBanks('/var/cache/norwegianbanks', new \GuzzleHttp\Client(['timeout' => 5]));
\Ariselseng\NorwegianBanks\NorwegianBanksStatic::setInstance($banks);
```

If the directory is not writable but already contains the table, that copy is used as is, without checking bits.no.

## Tests

```
vendor/bin/phpunit                 # offline
vendor/bin/phpunit --group live    # against bits.no
```
