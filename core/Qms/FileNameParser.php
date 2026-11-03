<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

/**
 * Toplu yüklemede dosya yollarından doküman satırları çıkarır. Sistem kod ya da ad üretmez; hepsi dosya ve klasör adından okunur.
 *
 *   FORMLAR/FR-135 Numune Kabul Formu rev02.pdf
 *     → kod FR135, ad "Numune Kabul Formu", revizyon 2
 *   FORMLAR/FR06- PERSONEL … FORMU/FR06- PERSONEL … FORMU-ik-rev01.pdf
 *     → kod FR06, ad klasörden "PERSONEL … FORMU", varyant "ik", revizyon 1
 *   FORMLAR/FR57- … FORMU ÖNYÜZ.jpg + FR57- … FORMU ARKA YÜZ.jpg
 *     → klasörsüz aynı kodlu dosyalarda ortak baş kısım ad, kalanı varyant olur ("ÖNYÜZ", "ARKA YÜZ")
 *
 * Aynı kod + varyanttaki PDF (ya da görsel) imzaya giden ana dosyadır; Excel/Word aynı satıra özgün dosya olarak eklenir.
 * Satırın tipi kod önekinden bulunur; dosya başka bir tipin klasöründeyse uyarı verilir (tipi yine önek belirler).
 * Dış kaynaklı klasöründeki dosyanın kodu okunmaz (kuruluşun kodu varsa kontrol tablosunda girilir).
 *
 * Çıktı satırları veritabanına bakmaz; mevcut doküman, yinelenen satır ve imzacı kontrolleri ekran tarafında yapılır.
 */
final class FileNameParser
{
    public const MAIN_EXT   = ['pdf', 'jpg', 'jpeg', 'png'];
    public const SOURCE_EXT = ['xlsx', 'xls', 'docx', 'doc'];

    private const SEP = " \t-_.,:;()";

    /**
     * @param array<int, string> $paths   tarayıcıdaki göreli yollar (webkitRelativePath ya da dosya adı), sıra = dosya numarası
     * @param array<int, array{id:int, prefixes:string[], external:bool, categoryName:string}> $docTypes  aktif doküman tipleri
     * @return array<int, array<string, mixed>>
     */
    public static function parse(array $paths, array $docTypes): array
    {
        $byPrefix = [];
        $external = null;
        $byCategory = [];
        foreach ($docTypes as $t) {
            foreach ($t['prefixes'] as $p) {
                $byPrefix[self::upper($p)] = $t;
            }
            if ($t['external']) {
                $external = $t;
            }
            $byCategory[self::fold($t['categoryName'])] = $t;
        }

        // 1. Her dosya: uzantı, klasörler, kod, kalan ad, revizyon
        $files = [];
        $rows = [];
        foreach ($paths as $index => $path) {
            // Mac'te adlandırılan dosyalarda Ç, Ş… ayrık gelir (C + çengel); karşılaştırma için birleşik yazılır
            $path     = \Normalizer::normalize((string) $path, \Normalizer::FORM_C) ?: (string) $path;
            $parts    = array_values(array_filter(preg_split('#[\\\\/]+#', $path), fn ($s) => $s !== ''));
            $fileName = (string) array_pop($parts);
            $dot      = mb_strrpos($fileName, '.');
            $ext      = $dot === false ? '' : mb_strtolower(mb_substr($fileName, $dot + 1));
            $base     = $dot === false ? $fileName : mb_substr($fileName, 0, $dot);
            $file = ['index' => (int) $index, 'name' => $fileName, 'ext' => $ext,
                     'kind' => in_array($ext, self::MAIN_EXT, true) ? 'main' : (in_array($ext, self::SOURCE_EXT, true) ? 'source' : null)];

            if ($file['kind'] === null) {
                $rows[] = self::row(null, null, $base, '', null, [$file], ['ERR_FILE_TYPE'], []);
                continue;
            }

            // Klasörler: kategori klasörü (FORMLAR) ve kodla başlayan grup klasörü (FR06- …)
            $categoryType = null;
            $categoryFolder = null;
            foreach ($parts as $folder) {
                if (isset($byCategory[self::fold($folder)])) {
                    $categoryType = $byCategory[self::fold($folder)];
                    $categoryFolder = $folder;
                }
            }
            $group = $parts ? self::splitCode(end($parts)) : null;

            [$revNo, $base] = self::takeRevision($base);
            $code = ($categoryType !== null && $categoryType['external']) ? null : self::splitCode($base);

            $file += ['categoryType' => $categoryType, 'categoryFolder' => $categoryFolder, 'revNo' => $revNo];
            if ($code === null) {
                // Kodsuz: dış kaynaklı klasöründeyse dış kaynaklı doküman, değilse kod okunamadı
                $type = ($categoryType !== null && $categoryType['external']) ? $categoryType : null;
                $rows[] = self::row($type, null, self::clean($base), '', $revNo, [$file], $type === null ? ['ERR_NO_CODE'] : [], []);
                continue;
            }
            $file += ['code' => $code['code'], 'prefix' => $code['prefix'], 'rest' => $code['rest'],
                      'groupTitle' => ($group !== null && $group['code'] === $code['code']) ? self::clean($group['rest']) : null];
            $files[$code['code']][] = $file;
        }

        // 2. Kod gruplarında ad ve varyant: grup klasörü varsa ad klasörden; yoksa aynı kodlu dosyaların ortak baş kısmı
        foreach ($files as $code => $list) {
            $commonTitle = null;
            $plain = array_values(array_filter($list, fn ($f) => $f['groupTitle'] === null));
            if (count($plain) > 1) {
                $commonTitle = self::commonPrefix(array_map(fn ($f) => $f['rest'], $plain));
            }

            $byKey = [];
            foreach ($list as $f) {
                $title = $f['groupTitle'] ?? $commonTitle ?? self::clean($f['rest']);
                $variant = self::clean(self::afterTitle($f['rest'], $title) ?? $f['rest']);
                if ($title === '' && $variant !== '') {
                    [$title, $variant] = [$variant, ''];
                }
                $key = self::fold($variant);
                $byKey[$key]['title']   ??= $title;
                $byKey[$key]['variant'] ??= $variant;
                $byKey[$key]['files'][] = $f;
            }

            foreach ($byKey as $g) {
                $first = $g['files'][0];
                $type  = $byPrefix[self::upper($first['prefix'])] ?? null;
                $errors = [];
                $warnings = [];
                $mains   = array_filter($g['files'], fn ($f) => $f['kind'] === 'main');
                $sources = array_filter($g['files'], fn ($f) => $f['kind'] === 'source');

                if ($type === null) {
                    $errors[] = 'ERR_PREFIX';
                }
                if (count($mains) > 1 || count($sources) > 1) {
                    $errors[] = 'ERR_DUPLICATE_FILES';
                } elseif (count($mains) === 0) {
                    $errors[] = 'ERR_NO_MAIN';
                }
                $revs = array_values(array_unique(array_filter(array_map(fn ($f) => $f['revNo'], $g['files']), fn ($r) => $r !== null)));
                if (count($revs) > 1) {
                    $warnings[] = 'WRN_REVISION_MISMATCH';
                }
                $mainRev = $mains ? array_values($mains)[0]['revNo'] : null;
                foreach ($g['files'] as $f) {
                    if ($type !== null && $f['categoryType'] !== null && $f['categoryType']['id'] !== $type['id']) {
                        $warnings[] = 'WRN_FOLDER';
                        break;
                    }
                }
                $rows[] = self::row($type, $code, $g['title'], $g['variant'], $mainRev ?? ($revs[0] ?? null),
                                    $g['files'], $errors, array_values(array_unique($warnings)));
            }
        }

        usort($rows, fn ($a, $b) => [$a['docCode'] === null, (string) $a['docCode'], $a['variantName'], $a['docTitle']]
                                <=> [$b['docCode'] === null, (string) $b['docCode'], $b['variantName'], $b['docTitle']]);
        return $rows;
    }

    /**
     * "FR-135 Numune Kabul" → [prefix FR, code FR135, rest "Numune Kabul"]. Numara en az iki hane yazılır (FR6 → FR06).
     * @return array{prefix:string, code:string, rest:string}|null
     */
    public static function splitCode(string $text): ?array
    {
        if (!preg_match('/^\s*([A-Za-zÇĞİÖŞÜçğıöşü]{1,6})\s*[-_ ]?\s*(\d{1,4})(?!\d)(.*)$/su', $text, $m)) {
            return null;
        }
        $prefix = self::upper($m[1]);
        $number = str_pad(ltrim($m[2], '0'), 2, '0', STR_PAD_LEFT);
        return ['prefix' => $prefix, 'code' => $prefix . $number, 'rest' => $m[3]];
    }

    /** "… FORMU-ik-rev01 " → [1, "… FORMU-ik"]. Revizyon yazmıyorsa [null, metin]. */
    public static function takeRevision(string $text): array
    {
        if (preg_match('/^(.*?)[\s\-_.]*\brev(?:izyon)?\s*\.?\s*(?:no\s*[:.]?\s*)?(\d{1,3})\b(.*)$/isu', $text, $m)) {
            return [(int) $m[2], rtrim($m[1]) . ($m[3] !== '' ? ' ' . $m[3] : '')];
        }
        return [null, $text];
    }

    // Satır: ekranda kontrol tablosuna düşen kayıt
    private static function row(?array $type, ?string $code, string $title, string $variant, ?int $revNo, array $files, array $errors, array $warnings): array
    {
        $main = $source = null;
        foreach ($files as $f) {
            if ($f['kind'] === 'main') {
                $main ??= ['index' => $f['index'], 'name' => $f['name']];
            } elseif ($f['kind'] === 'source') {
                $source ??= ['index' => $f['index'], 'name' => $f['name']];
            }
        }
        $folder = null;
        foreach ($files as $f) {
            $folder ??= $f['categoryFolder'] ?? null;
        }
        return [
            'docTypeId'   => $type['id'] ?? null,
            'docCode'     => $code,
            'variantName' => $variant,
            'docTitle'    => $title,
            'revisionNo'  => $revNo,
            'folder'      => $folder,
            'mainFile'    => $main,
            'sourceFile'  => $source,
            'fileNames'   => array_map(fn ($f) => $f['name'], $files),
            'fileIndexes' => array_map(fn ($f) => $f['index'], $files),
            'errors'      => $errors,
            'warnings'    => $warnings,
        ];
    }

    // Ortak baş kısım: bütün metinlerde aynı olan baştaki kelimeler (büyük/küçük harf ve noktalama farkı sayılmaz)
    private static function commonPrefix(array $texts): string
    {
        $tokenLists = array_map(fn ($t) => self::tokens($t), $texts);
        $n = min(array_map('count', $tokenLists));
        $k = 0;
        while ($k < $n && count(array_unique(array_map(fn ($l) => $l[$k], $tokenLists))) === 1) {
            $k++;
        }
        if ($k === 0) {
            return '';
        }
        $first = $texts[0];
        $offset = self::tokenOffset($first, array_slice($tokenLists[0], 0, $k));
        return self::clean(mb_substr($first, 0, $offset));
    }

    // Metin adla başlıyorsa adın arkasında kalan kısım (varyant), başlamıyorsa null
    private static function afterTitle(string $text, string $title): ?string
    {
        $titleTokens = self::tokens($title);
        if (!$titleTokens) {
            return $text;
        }
        $offset = self::tokenOffset($text, $titleTokens);
        return $offset === null ? null : mb_substr($text, $offset);
    }

    // Metnin başındaki kelimeler verilen kelimelerse bitiş konumu (karakter), değilse null
    private static function tokenOffset(string $text, array $tokens): ?int
    {
        $f = self::fold($text);
        $len = mb_strlen($f);
        $pos = 0;
        foreach ($tokens as $t) {
            while ($pos < $len && !preg_match('/[\p{L}\p{N}]/u', mb_substr($f, $pos, 1))) {
                $pos++;
            }
            $tl = mb_strlen($t);
            if (mb_substr($f, $pos, $tl) !== $t) {
                return null;
            }
            $pos += $tl;
        }
        return $pos;
    }

    private static function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', self::fold($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    // Karşılaştırma için Türkçe küçük harf; karakter sayısı korunur (İ → i, I → ı)
    public static function fold(string $text): string
    {
        return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text), 'UTF-8');
    }

    private static function upper(string $text): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $text), 'UTF-8');
    }

    // Baştaki ve sondaki ayraçlar, fazla boşluklar atılır
    private static function clean(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text, self::SEP);
    }
}
