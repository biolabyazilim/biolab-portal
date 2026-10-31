<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

/**
 * Bağımlılıksız, salt okunur xlsx okuyucu (PhpSpreadsheet yok; xlsx bir zip + XML'dir).
 * Formüllü hücrelerde Excel'in kaydettiği son değer okunur (ör. VLOOKUP sonucu).
 *
 *   $rows = (new XlsxReader($path))->sheet('TÜM TEST LİSTESİ');   // [satırNo => [kolonNo(1..) => metin]]
 */
final class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string, string> sayfa adı → zip içindeki xml yolu */
    private array $sheets = [];
    /** @var list<string> */
    private array $shared = [];

    public function __construct(private string $path)
    {
        $zip = $this->open();
        try {
            $this->shared = $this->readSharedStrings($zip);
            $this->sheets = $this->readSheetMap($zip);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /** @return array<int, array<int, string>> */
    public function sheet(string $name): array
    {
        if (!isset($this->sheets[$name])) {
            throw new \RuntimeException("Excel'de '{$name}' sayfası yok. Sayfalar: " . implode(', ', $this->sheetNames()));
        }
        $zip = $this->open();
        try {
            $xml = $zip->getFromName($this->sheets[$name]);
        } finally {
            $zip->close();
        }
        $doc = simplexml_load_string((string) $xml);
        $doc->registerXPathNamespace('m', self::NS_MAIN);

        $rows = [];
        foreach ($doc->xpath('//m:sheetData/m:row') as $row) {
            $r = (int) $row['r'];
            foreach ($row->c as $c) {
                $col = self::columnIndex((string) $c['r']);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $this->shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = self::text($c->is);
                } else {
                    $val = (string) $c->v;   // sayı, formül sonucu (str), boolean
                }
                if ($val !== '') {
                    $rows[$r][$col] = $val;
                }
            }
        }
        ksort($rows);
        return $rows;
    }

    private function open(): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException("Excel dosyası açılamadı: {$this->path}");
        }
        return $zip;
    }

    /** @return list<string> */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (simplexml_load_string($xml)->si as $si) {
            $out[] = self::text($si);
        }
        return $out;
    }

    /** @return array<string, string> */
    private function readSheetMap(\ZipArchive $zip): array
    {
        $rels = [];
        $relXml = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($relXml->Relationship as $rel) {
            $target = ltrim((string) $rel['Target'], '/');
            $rels[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        $map = [];
        $wb = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        foreach ($wb->sheets->sheet as $sheet) {
            $rid = (string) $sheet->attributes(self::NS_REL)['id'];
            $map[(string) $sheet['name']] = $rels[$rid] ?? '';
        }
        return $map;
    }

    /** Zengin metin parçaları (<r><t>) dahil hücre metni */
    private static function text(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r as $r) {
            $s .= (string) $r->t;
        }
        return $s;
    }

    /** "AB12" → 28 */
    private static function columnIndex(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }
}
