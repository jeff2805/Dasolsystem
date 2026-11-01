<?php

namespace App\Support;

use ZipArchive;

/**
 * A small writer for real .xlsx workbooks.
 *
 * The office files its monthly return to the Department of Tourism as a
 * spreadsheet, on a form with coloured headings. A CSV would open in Excel but
 * would arrive as one grey sheet with the headings in the first row, and the
 * office would re-make the form by hand every month.
 *
 * An .xlsx file is a ZIP of XML parts, so PHP's own ZipArchive is enough and
 * no library is added for it. Deliberately narrow: one sheet, text and whole
 * numbers, a coloured bold heading row, and column widths. Anything more —
 * formulas, dates, merged cells, several sheets — belongs in a real library
 * rather than here, and this class should not grow into one.
 */
final class Xlsx
{
    /** Heading fills, in the order headings are given. Excel wants RRGGBB. */
    private const DEFAULT_FILL = 'D9D9D9';

    /**
     * @param  list<string>  $headings
     * @param  list<string>  $fills  one colour per heading, RRGGBB
     * @param  list<float>  $widths  column widths, in characters
     */
    public function __construct(
        private readonly string $sheetName,
        private readonly array $headings,
        private readonly array $fills = [],
        private readonly array $widths = [],
    ) {}

    /**
     * Writes the workbook to a temporary file and returns its path.
     *
     * The caller is responsible for sending and deleting it; a streamed
     * download cannot be used, because a ZIP has to be finished before its
     * first byte is correct.
     *
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function write(iterable $rows, bool $lastRowIsTotal = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vdxlsx');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The spreadsheet file could not be opened for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows, $lastRowIsTotal));

        $zip->close();

        return $path;
    }

    /** @param  iterable<int, list<string|int|float|null>>  $rows */
    private function sheet(iterable $rows, bool $lastRowIsTotal = false): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->widths !== []) {
            $xml .= '<cols>';

            foreach ($this->widths as $index => $width) {
                $column = $index + 1;
                $xml .= '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
            }

            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        // The heading row: style 1 upwards, one per column, so each keeps its
        // own colour from the form.
        $xml .= '<row r="1">';

        foreach ($this->headings as $index => $heading) {
            $xml .= $this->cell($index, 1, $heading, $index + 1);
        }

        $xml .= '</row>';

        $number = 1;
        $rowCount = is_countable($rows) ? count($rows) : null;
        $position = 0;

        foreach ($rows as $row) {
            $number++;
            $position++;
            $isTotal = $lastRowIsTotal && $rowCount !== null && $position === $rowCount;
            $xml .= '<row r="'.$number.'">';

            foreach (array_values($row) as $index => $value) {
                $style = $isTotal ? count($this->headings) + 1 : 0;
                $xml .= $this->cell($index, $number, $value, $style);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** One cell: a number as a number, anything else as inline text. */
    private function cell(int $index, int $row, string|int|float|null $value, int $style): string
    {
        $reference = self::columnName($index).$row;
        $styleAttribute = $style > 0 ? ' s="'.$style.'"' : '';

        if ($value === null || $value === '') {
            return '<c r="'.$reference.'"'.$styleAttribute.'/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$reference.'"'.$styleAttribute.'><v>'.$value.'</v></c>';
        }

        return '<c r="'.$reference.'"'.$styleAttribute.' t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</t></is></c>';
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + ($number - 1) % 26).$name;
        }

        return $name;
    }

    private function styles(): string
    {
        $fills = '<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>';

        $cellXfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';

        foreach ($this->headings as $index => $heading) {
            $colour = $this->fills[$index] ?? self::DEFAULT_FILL;

            $fills .= '<fill><patternFill patternType="solid">'
                .'<fgColor rgb="FF'.$colour.'"/><bgColor indexed="64"/>'
                .'</patternFill></fill>';

            // Fills 0 and 1 are reserved by the format, so this one's index is
            // its position plus two.
            $cellXfs .= '<xf numFmtId="0" fontId="1" fillId="'.($index + 2).'" borderId="1"'
                .' xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
                .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        }

        // One extra bold style is reserved for the final GRAND TOTAL row.
        $cellXfs .= '<xf numFmtId="0" fontId="1" fillId="1" borderId="1"'
            .' xfId="0" applyFont="1" applyBorder="1">'
            .'<alignment vertical="center"/></xf>';

        $fillCount = count($this->headings) + 2;
        $xfCount = count($this->headings) + 2;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="'.$fillCount.'">'.$fills.'</fills>'
            .'<borders count="2"><border/>'
            .'<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.$xfCount.'">'.$cellXfs.'</cellXfs>'
            .'</styleSheet>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($this->sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }
}
