<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

/**
 * Bağımlılıksız xlsx yazıcı (PhpSpreadsheet yok; xlsx bir zip + XML'dir). Maliyet Excel çıktıları için yeterli kadar:
 * metin, sayı, formül (Excel açılışta yeniden hesaplar), birkaç sabit stil, kolon genişliği, başlık satırını dondurma, filtre.
 *
 *   $w = new XlsxWriter();
 *   $s = $w->sheet('PATOLOJİ', [10, 40, 14]);                  // kolon genişlikleri (karakter)
 *   $s->row(['Kod', 'Adı', 'Toplam'], XlsxWriter::HEADER);      // tüm satıra tek stil
 *   $s->row(['PAT09', 'ABORTUS', ['f' => 'SUM(G2:Q2)', 'v' => 9.3, 's' => XlsxWriter::MONEY_TOTAL]]);
 *   $bytes = $w->bytes();
 *
 * Hücre: skaler (null = boş) ya da ['v' => değer, 'f' => formül ("=" olmadan), 's' => stil].
 */
final class XlsxWriter
{
    public const TEXT        = 2;   // ince kenarlıklı metin
    public const HEADER      = 1;   // kalın, yeşil zemin, ortalı, satır kaydırmalı
    public const MONEY       = 3;   // #,##0.00
    public const MONEY_TOTAL = 4;   // #,##0.00 kalın, mavi zemin
    public const UNIT_COST   = 5;   // #,##0.0000
    public const WARN        = 6;   // sarı zeminli metin
    public const NUMBER      = 7;   // genel sayı
    public const TITLE       = 8;   // kalın, kenarlıksız

    /** @var list<XlsxSheet> */
    private array $sheets = [];

    public function sheet(string $name, array $widths = []): XlsxSheet
    {
        // Excel sayfa adı: en çok 31 karakter, [ ] : * ? / \ yok
        $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $name), 0, 31);
        return $this->sheets[] = new XlsxSheet($name, $widths);
    }

    public function bytes(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Excel dosyası oluşturulamadı.');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($n));
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($n));
        $zip->addFromString('xl/styles.xml', self::STYLES);
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $s->xml());
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    public static function col(int $index): string
    {
        $s = '';
        for ($n = $index; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** Formülde sayfa adı: 'MALZEME FİYATLARI'!H7 */
    public static function ref(string $sheet, string $cell): string
    {
        return "'" . str_replace("'", "''", $sheet) . "'!" . $cell;
    }

    private function contentTypes(int $n): string
    {
        $sheets = '';
        for ($i = 1; $i <= $n; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $sheets .= '<sheet name="' . self::x($s->name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $names = '';
        foreach ($this->sheets as $i => $s) {
            if ($s->filterRef !== null) {   // otomatik filtre için gizli ad (Excel bunu bekler)
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">'
                    . self::x(self::ref($s->name, $s->absRef($s->filterRef))) . '</definedName>';
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . ($names ? '<definedNames>' . $names . '</definedNames>' : '')
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/>'   // açılışta formüller yeniden hesaplanır
            . '</workbook>';
    }

    private function workbookRels(int $n): string
    {
        $rels = '';
        for ($i = 1; $i <= $n; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    public static function x(string $s): string
    {
        // XML'de geçersiz kontrol karakterleri atılır
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.0000"/></numFmts>'
        . '<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFC6E0B4"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                  // 0 varsayılan
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        .   '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'                                                        // 1 başlık
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                // 2 metin
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                        // 3 TL
        . '<xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 4 toplam
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                        // 5 birim maliyet
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                  // 6 uyarı
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                                // 7 sayı
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                  // 8 başlık yazısı
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** XlsxWriter'ın tek sayfası */
final class XlsxSheet
{
    /** @var list<string> */
    private array $rows = [];
    private int $maxCol = 0;
    private ?int $freezeRow = null;
    public ?string $filterRef = null;

    public function __construct(public readonly string $name, private array $widths) {}

    /** Satır ekler, satır numarasını döndürür (1'den) */
    public function row(array $cells, ?int $style = null): int
    {
        $r = count($this->rows) + 1;
        $xml = '';
        foreach (array_values($cells) as $i => $cell) {
            $ref = XlsxWriter::col($i + 1) . $r;
            $v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
            $f = is_array($cell) ? ($cell['f'] ?? null) : null;
            $s = is_array($cell) && isset($cell['s']) ? $cell['s'] : $style;
            $sAttr = $s !== null ? ' s="' . $s . '"' : '';
            if ($f !== null) {
                $xml .= '<c r="' . $ref . '"' . $sAttr . (is_string($v) ? ' t="str"' : '') . '><f>' . XlsxWriter::x($f) . '</f>'
                    . ($v !== null && $v !== '' ? '<v>' . XlsxWriter::x(is_string($v) ? $v : self::num($v)) . '</v>' : '') . '</c>';
            } elseif ($v === null || $v === '') {
                if ($sAttr !== '') { $xml .= '<c r="' . $ref . '"' . $sAttr . '/>'; }
            } elseif (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . self::num($v) . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . XlsxWriter::x((string) $v) . '</t></is></c>';
            }
        }
        $this->maxCol = max($this->maxCol, count($cells));
        $this->rows[] = '<row r="' . $r . '">' . $xml . '</row>';
        return $r;
    }

    /** Bu satırın altını dondurur (başlık kaydırırken görünür kalır) */
    public function freeze(int $row): self
    {
        $this->freezeRow = $row;
        return $this;
    }

    /** Otomatik filtre: başlık satırından son satıra */
    public function autoFilter(int $headerRow, int $lastCol): self
    {
        $this->filterRef = 'A' . $headerRow . ':' . XlsxWriter::col($lastCol) . max($headerRow, count($this->rows));
        return $this;
    }

    public function absRef(string $ref): string
    {
        return preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $ref);
    }

    public function xml(): string
    {
        $view = '<sheetView workbookViewId="0">';
        if ($this->freezeRow) {
            $top = 'A' . ($this->freezeRow + 1);
            $view .= '<pane ySplit="' . $this->freezeRow . '" topLeftCell="' . $top . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="' . $top . '" sqref="' . $top . '"/>';
        }
        $view .= '</sheetView>';

        $cols = '';
        foreach ($this->widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews>' . $view . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="13.5"/>'
            . ($cols ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . implode('', $this->rows) . '</sheetData>'
            . ($this->filterRef ? '<autoFilter ref="' . $this->filterRef . '"/>' : '')
            . '</worksheet>';
    }

    private static function num(int|float $v): string
    {
        // Ondalık ayırıcı nokta, bilimsel gösterim yok
        return is_int($v) ? (string) $v : rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
    }
}
