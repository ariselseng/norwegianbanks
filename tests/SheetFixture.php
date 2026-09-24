<?php

namespace Ariselseng\NorwegianBanks\Tests;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds an xlsx shaped like the IBAN/BIC table from bits.no, including the kinds of rows the parser has to cope with.
 */
class SheetFixture
{
    public const BULK_PREFIXES_PER_BANK = 20;

    public static function xlsx(int $bulkBanks = 60): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            ['1594', 'DNBANOKK', 'DNB Bank ASA'],
            ['1600', 'DNBANOKK', 'DNB Bank ASA '],
            [6105, 'NDEANOKK', 'Nordea Bank Abp, filial i Norge'],
            ['3000', 'SPSONO22', 'Sparebanken Sør'],
            ['3920', 'NORVNO21', 'SpareBank 1 Nordmøre '],
            ['4213', null, 'Bank uten BIC A'],
            ['9960', null, 'Bank uten BIC B'],
            // A prefix stored as a number has lost its leading zero in the cell.
            [530, 'DNBANOKK', 'DNB Bank ASA'],
            // Rows that must be skipped: a duplicate prefix, a prefix without a name, blanks and a footnote.
            ['1594', 'OTHRNOKK', 'Some other bank'],
            ['8888', 'NONANOKK', null],
            ['  ', ' ', ' '],
            [null, null, null],
            ['* Sist oppdatert 08.09.26', null, null],
        ];
        for ($bank = 0; $bank < $bulkBanks; $bank++) {
            for ($i = 0; $i < self::BULK_PREFIXES_PER_BANK; $i++) {
                $rows[] = [(string)(7000 + $bank * self::BULK_PREFIXES_PER_BANK + $i), sprintf('TEST%02dNO', $bank), "Testbank $bank"];
            }
        }

        $sheet->fromArray(['Bank identifier', 'BIC', 'Bank']);
        foreach ($rows as $i => [$prefix, $bic, $name]) {
            $row = $i + 2;
            if ($prefix !== null) {
                // Like the real file, prefixes are text unless the fixture says otherwise.
                $sheet->setCellValueExplicit("A$row", $prefix, is_int($prefix) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            if ($bic !== null) {
                $sheet->setCellValueExplicit("B$row", $bic, DataType::TYPE_STRING);
            }
            if ($name !== null) {
                $sheet->setCellValueExplicit("C$row", $name, DataType::TYPE_STRING);
            }
        }

        // A styled but empty row and a hidden row below the data.
        $row = count($rows) + 3;
        $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
        $sheet->getRowDimension($row + 1)->setVisible(false);

        $file = tempnam(sys_get_temp_dir(), 'norwegianbanks-fixture');
        (new Xlsx($spreadsheet))->save($file);
        $contents = file_get_contents($file);
        unlink($file);
        return $contents;
    }
}
