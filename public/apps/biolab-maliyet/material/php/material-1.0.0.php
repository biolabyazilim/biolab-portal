<?php
/**
 * Maliyet — Malzeme kataloğu ve fiyatlar
 *
 *   Seçim kutuları (lib.js $.fn.slb; select id = işlem adı, pasif kayıt listede görünür ama seçilemez):
 *   getSTUDY_ID      → maliyet çalışmaları
 *   getCATEGORY_ID   → malzeme kategorileri (mlyt_material_category; filtre ve formdaki kutu aynı listeyi kullanır)
 *   getSUPPLIER_ID   → tedarikçiler
 *   getUSAGE_UNIT    → kullanım birimleri (adet, test, ml, gr)
 *   getCURRENCY      → para birimleri (TRY, USD, EUR, GBP)
 *   getTEST_ID       → malzemenin (kitin) bağlanabileceği testler (hesaplamalı testler hariç)
 *
 *   getGridList      → malzeme listesi (jqGrid; sayfalama, sıralama, sekme / kategori / arama filtresi sunucuda)
 *   getSelectedId    → seçilen malzeme + seçili çalışmadaki fiyatı + kullanıldığı testler (formu doldurur)
 *   setStudy         → çalışma ekle / düzenle (ad, kur tarihi, USD / EUR / GBP kuru, TL öngörü katsayısı, aylık iş günü)
 *   setMaterial      → malzeme ekle / düzenle; fiyat alanları doluysa seçili çalışmanın fiyatını yazar
 *   setMaterialDelete→ malzemeyi siler (RECORD_FL = 0)
 *   setMaterialTest  → malzemeyi teste bağlar (reçete satırı, hasta başı); varsa miktarı günceller
 *   setMaterialTestDelete → test bağlantısını (reçete satırını) kaldırır
 *
 * Kit: "Kit" kategorisinde bir malzemedir. Ambalaj içeriği = kitteki test sayısı, ambalaj fiyatı = kit fiyatı,
 * hasta sayısı (PATIENT_QTY, fiyatta) = kitten çıkan hasta. Teste bağlantısı reçete satırıdır; test maliyeti ekranı aynı satırı görür.
 * Hasta sayısı kit dışında da girilebilir (ICP-MS standardı: bir şişe kaç hastaya yeter).
 *
 * Fiyat ve kur yalnızca TASLAK çalışmada değiştirilebilir; onaylanan çalışma salt okunurdur.
 * Birim maliyet (TL) = ambalaj fiyatı × kur ÷ (hasta sayısı girilmişse o, yoksa ambalaj içeriği).
 * TL'nin kuru çalışmanın öngörü katsayısıdır (TRY_FACTOR; Excel'de TL kuru 1,5).
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\MaterialPrice;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\Study;
use BIO\MLYT\StudyRate;
use BIO\MLYT\StudySupplierRate;
use BIO\MLYT\Supplier;
use BIO\MLYT\Test;
use BIO\MNG\Company;

$db = $dbm->getDB();
$resourceFile = "material/material-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

// Kullanım birimleri: kod → [tr, en]
const MT_UNIT_NAMES = array(
    Material::UNIT_PIECE => array("adet", "piece"),
    Material::UNIT_TEST  => array("test", "test"),
    Material::UNIT_ML    => array("ml", "ml"),
    Material::UNIT_GR    => array("gr", "g"),
);
const MT_UNITS = array(Material::UNIT_PIECE, Material::UNIT_TEST, Material::UNIT_ML, Material::UNIT_GR);
const MT_RATE_CURRENCIES = array(StudyRate::CURRENCY_USD, StudyRate::CURRENCY_EUR, StudyRate::CURRENCY_GBP);
const MT_PRICE_CURRENCIES = array(StudyRate::CURRENCY_TRY, StudyRate::CURRENCY_USD, StudyRate::CURRENCY_EUR, StudyRate::CURRENCY_GBP);

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionLang = (string) ($_SESSION["lang"] ?? "tr");

switch ($query) {

    /* ---------- Seçim kutuları ---------- */

    case "getSTUDY_ID":
        $execType = ET_DEFAULT;
        try{
            $statusText = array(Study::STATUS_DRAFT => "Taslak", Study::STATUS_REVIEW => "İncelemede",
                                Study::STATUS_APPROVED => "Onaylandı", Study::STATUS_ARCHIVED => "Arşiv");
            $rows = $db->createQuery("
                SELECT s.studyId, s.studyName, s.status, s.activeFl
                  FROM BIO\MLYT\Study s
                 WHERE IDENTITY(s.company) = :COMPANY_ID AND s.recordFl > 0
                 ORDER BY s.rateDate DESC, s.studyId DESC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($rows as &$r) {
                $r["studyText"] = $r["studyName"] . " · " . ($statusText[$r["status"]] ?? $r["status"]);
            }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getCATEGORY_ID":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT c.categoryId, c.categoryCode, c.categoryName, c.categoryNameEn, c.activeFl
                  FROM BIO\MLYT\MaterialCategory c
                 WHERE c.recordFl > 0
                 ORDER BY c.sortNo, c.categoryName
            ")->getArrayResult();
            // Dil seçimi PHP'de: iki ad kolonunun harmanlaması farklı (TR / UTF8), SQL'de COALESCE çakışıyor
            foreach ($rows as &$r) {
                if ($sessionLang === "en" && $r["categoryNameEn"]) { $r["categoryName"] = $r["categoryNameEn"]; }
                unset($r["categoryNameEn"]);
            }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getSUPPLIER_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT s.supplierId, s.supplierName, s.activeFl
                  FROM BIO\MLYT\Supplier s
                 WHERE IDENTITY(s.company) = :COMPANY_ID AND s.recordFl > 0
                 ORDER BY s.supplierName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getUSAGE_UNIT":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (MT_UNIT_NAMES as $code => $names) {
            $dtResponse[] = array("usageUnit" => $code, "usageUnitName" => $names[$sessionLang === "en" ? 1 : 0], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    case "getCURRENCY":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (MT_PRICE_CURRENCIES as $cur) {
            $dtResponse[] = array("currency" => $cur, "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    // Bağlanabilecek testler: aktif, hesaplamalı olmayan (hesaplamalının maliyeti bileşenlerinden gelir)
    case "getTEST_ID":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT t.testId, t.testCode, t.testName, t.activeFl
                  FROM BIO\MLYT\Test t
                 WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.costMethod <> :CALCULATED
                 ORDER BY t.testCode
            ")->setParameters(array("COMPANY_ID" => $companyId, "CALCULATED" => Test::METHOD_CALCULATED))->getArrayResult();
            foreach ($rows as &$r) {
                $r["testText"] = $r["testCode"] . " — " . $r["testName"];
            }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Bir çalışmanın kurları, TL katsayısı ve tedarikçi kurları (yeni çalışmada "fiyatları kopyala" seçilince forma dolar)
    case "getStudyInfo":
        $execType = ET_DEFAULT;
        try{
            $study = mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            if ($study === null) { $resCode = 20; $resMsg = "Çalışma bulunamadı."; break; }
            $dtResponse = mtStudyToArray($study, mtRates($db, $study), mtSupplierRates($db, $study));
            $dtResponse["priceCount"] = (int) $db->createQuery("SELECT COUNT(p.materialPriceId) FROM BIO\MLYT\MaterialPrice p WHERE p.study = :S")
                ->setParameter("S", $study)->getSingleScalarResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Ekran işlemleri ---------- */
    case "setStudy":
        $execType = ET_CUSTOM;
        try{
            $name     = trim((string) ($_POST["STUDY_NAME"] ?? ""));
            $rateDate = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["RATE_DATE"] ?? ""));
            $workDays = (int) ($_POST["WORK_DAYS_PER_MONTH"] ?? 26);
            $tryFactor = mtDecimal($_POST["TRY_FACTOR"] ?? null) ?? "1";
            $rates    = array();
            foreach (MT_RATE_CURRENCIES as $cur) {
                $rates[$cur] = mtDecimal($_POST["RATE_" . $cur] ?? null);
            }

            $errors = array();
            if ($name === "") { $errors[] = "Çalışma adını girin."; }
            if (!$rateDate) { $errors[] = "Kur tarihini gg.aa.yyyy biçiminde girin."; }
            if ($workDays < 1 || $workDays > 31) { $errors[] = "Aylık iş günü 1 ile 31 arasında olmalı."; }
            if ((float) $tryFactor <= 0 || (float) $tryFactor > 100) { $errors[] = "TL öngörü katsayısı sıfırdan büyük olmalı (ör. 1 ya da 1,5)."; }
            foreach ($rates as $cur => $v) {
                if ($v !== null && (float) $v <= 0) { $errors[] = $cur . " kuru sıfırdan büyük olmalı."; }
            }
            // Tedarikçi sözleşme kurları: JSON [{supplierId, currency, rate}] — listenin tamamı (listede olmayan silinir)
            $supplierRates = null;
            if (isset($_POST["SUPPLIER_RATES"])) {
                $supplierRates = array();
                foreach ((array) json_decode((string) $_POST["SUPPLIER_RATES"], true) as $i => $sr) {
                    $sid = (int) ($sr["supplierId"] ?? 0);
                    $cur = (string) ($sr["currency"] ?? "");
                    $v   = mtDecimal($sr["rate"] ?? null);
                    $row = "Tedarikçi kuru " . ($i + 1) . ": ";
                    $sup = $sid > 0 ? $db->getRepository(Supplier::class)->findOneBy(array("supplierId" => $sid, "company" => $companyId)) : null;
                    if ($sup === null) { $errors[] = $row . "tedarikçi seçin."; continue; }
                    if (!in_array($cur, MT_RATE_CURRENCIES, true)) { $errors[] = $row . "para birimi seçin."; continue; }
                    if ($v === null || (float) $v <= 0) { $errors[] = $row . "kur sıfırdan büyük olmalı."; continue; }
                    if (isset($supplierRates[$sid . "|" . $cur])) { $errors[] = $sup->getSupplierName() . " " . $cur . " iki kez girilmiş."; continue; }
                    $supplierRates[$sid . "|" . $cur] = array($sup, $cur, $v);
                }
            }
            if ($errors) {
                $resCode = 10;
                $resMsg = implode(" ", $errors);
                break;
            }

            $studyId = (int) ($_POST["STUDY_ID"] ?? 0);
            // Yeni çalışmada fiyatlar başka bir çalışmadan kopyalanabilir (COPY_FROM_STUDY_ID); kaynak değişmez
            $copyFrom = $studyId <= 0 ? mtFindStudy($db, (int) ($_POST["COPY_FROM_STUDY_ID"] ?? 0), $companyId) : null;
            if ($studyId > 0) {
                $study = mtFindStudy($db, $studyId, $companyId);
                if ($study === null) { $resCode = 20; $resMsg = "Çalışma bulunamadı."; break; }
                if (!$study->isEditable()) { $resCode = 30; $resMsg = "Onaylanmış çalışma değiştirilemez."; break; }
                $study->setStudyName($name)->setRateDate($rateDate);
            } else {
                $study = new Study($name, $rateDate);
                $study->setCompany($db->getReference(Company::class, $companyId));
                $db->persist($study);
            }
            $study->setWorkDaysPerMonth($workDays)->setTryFactor($tryFactor);

            // Kurlar: boş bırakılan döviz silinir, dolu olan eklenir / güncellenir
            $existing = array();
            foreach ($db->getRepository(StudyRate::class)->findBy(array("study" => $study)) as $r) {
                $existing[$r->getCurrency()] = $r;
            }
            foreach ($rates as $cur => $v) {
                if ($v === null) {
                    if (isset($existing[$cur])) { $db->remove($existing[$cur]); }
                } elseif (isset($existing[$cur])) {
                    $existing[$cur]->setRate($v);
                } else {
                    $db->persist(new StudyRate($study, $cur, $v));
                }
            }

            // Tedarikçi kurları: gönderildiyse listedeki yazılır, listede olmayan silinir
            if ($supplierRates !== null) {
                $existingSr = array();
                foreach ($study->getStudyId() ? $db->getRepository(StudySupplierRate::class)->findBy(array("study" => $study)) : array() as $r) {
                    $existingSr[$r->getSupplier()->getSupplierId() . "|" . $r->getCurrency()] = $r;
                }
                foreach ($supplierRates as $k => [$sup, $cur, $v]) {
                    if (isset($existingSr[$k])) { $existingSr[$k]->setRate($v); unset($existingSr[$k]); }
                    else { $db->persist(new StudySupplierRate($study, $sup, $cur, $v)); }
                }
                foreach ($existingSr as $r) { $db->remove($r); }
            }
            $db->flush();

            // Fiyat kopyası: kaynak çalışmanın fiyatları (silinmemiş malzemelerin) yeni çalışmaya, tek SQL ile
            $copied = 0;
            if ($copyFrom !== null) {
                $copied = (int) $db->getConnection()->executeStatement("
                    INSERT INTO mlyt_material_price (STUDY_ID, MATERIAL_ID, PACKAGE_DESC, PACKAGE_QTY, PATIENT_QTY, CURRENCY, PACKAGE_PRICE,
                                                     VAT_INCLUDED_FL, NOTE, ACTIVE_FL, RECORD_FL, C_BY)
                    SELECT ?, p.MATERIAL_ID, p.PACKAGE_DESC, p.PACKAGE_QTY, p.PATIENT_QTY, p.CURRENCY, p.PACKAGE_PRICE,
                           p.VAT_INCLUDED_FL, p.NOTE, p.ACTIVE_FL, 1, ?
                      FROM mlyt_material_price p
                      JOIN mlyt_material m ON m.MATERIAL_ID = p.MATERIAL_ID AND m.RECORD_FL > 0
                     WHERE p.STUDY_ID = ? AND p.RECORD_FL > 0
                ", array($study->getStudyId(), (int) ($_SESSION["USER_ID"] ?? 0) ?: null, $copyFrom->getStudyId()));
            }

            $resObject = array("studyId" => $study->getStudyId(), "copied" => $copied);
            $resCode = 0;
            $resMsg = $copyFrom !== null
                ? "Çalışma açıldı; " . $copied . " fiyat '" . $copyFrom->getStudyName() . "' çalışmasından kopyalandı"
                : "Çalışma kaydedildi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Malzeme listesi (jqGrid). Sayfalama / sıralama / filtre sunucuda.
    //   postData: STUDY_ID (fiyatların çalışması), TAB (all / missing / priced), CATEGORY_ID, SUPPLIER_ID, Q (malzeme / tedarikçi adı)
    //   resObject: study (seçili çalışma + kurlar, şerit için), counts (sekme sayıları; kategori ve aramadan bağımsız),
    //              kitCategoryId (formda kit alanlarını açmak için)
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $study   = mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            $studyId = $study ? $study->getStudyId() : 0;   // çalışma yoksa fiyat eşleşmez, liste fiyatsız gelir

            // Fiyat seçili çalışmadan; kur fiyatın para biriminden (TRY'de kur kaydı yok, öngörü katsayısı).
            // Malzemenin tedarikçisinin sözleşme kuru (sr) varsa o, yoksa çalışmanın genel kuru (r)
            $from = "
                  FROM BIO\MLYT\Material m
                  JOIN m.category c
                  LEFT JOIN m.supplier s
                  LEFT JOIN BIO\MLYT\MaterialPrice p WITH p.material = m AND p.study = :STUDY_ID
                  LEFT JOIN BIO\MLYT\StudyRate r WITH r.study = p.study AND r.currency = p.currency
                  LEFT JOIN BIO\MLYT\StudySupplierRate sr WITH sr.study = p.study AND sr.supplier = m.supplier
                                                             AND sr.currency = p.currency AND sr.recordFl > 0
                 WHERE IDENTITY(m.company) = :COMPANY_ID
                   AND m.recordFl > 0
            ";
            $params = array("COMPANY_ID" => $companyId, "STUDY_ID" => $studyId);

            // Sekme sayıları: yalnızca çalışmaya bağlı
            $cnt = $db->createQuery("
                SELECT COUNT(m.materialId) AS allCount,
                       SUM(CASE WHEN p.materialPriceId IS NOT NULL OR (c.categoryCode = 'BILLABLE' AND m.billablePrice IS NOT NULL) THEN 1 ELSE 0 END) AS pricedCount
            " . $from)->setParameters($params)->getSingleResult();

            $whereClause = "";
            $tab = (string) ($_POST["TAB"] ?? "all");
            // Bedelli malzeme, bedeli girilmişse fiyatlı sayılır (çalışmadan bağımsız)
            if ($tab === "missing") { $whereClause .= " AND p.materialPriceId IS NULL AND NOT (c.categoryCode = 'BILLABLE' AND m.billablePrice IS NOT NULL)"; }
            if ($tab === "priced")  { $whereClause .= " AND (p.materialPriceId IS NOT NULL OR (c.categoryCode = 'BILLABLE' AND m.billablePrice IS NOT NULL))"; }
            if ((int) ($_POST["CATEGORY_ID"] ?? 0) > 0) {
                $whereClause .= " AND c.categoryId = :CATEGORY_ID";
                $params["CATEGORY_ID"] = (int) $_POST["CATEGORY_ID"];
            }
            // Tedarikçi: -1 = tedarikçisi olmayanlar
            if ((int) ($_POST["SUPPLIER_ID"] ?? 0) === -1) {
                $whereClause .= " AND s.supplierId IS NULL";
            } elseif ((int) ($_POST["SUPPLIER_ID"] ?? 0) > 0) {
                $whereClause .= " AND s.supplierId = :SUPPLIER_ID";
                $params["SUPPLIER_ID"] = (int) $_POST["SUPPLIER_ID"];
            }
            // Para birimi: seçili çalışmadaki fiyatın para birimi (TRY / USD / EUR / GBP)
            if (in_array($_POST["CURRENCY"] ?? "", MT_PRICE_CURRENCIES, true)) {
                $whereClause .= " AND p.currency = :CURRENCY";
                $params["CURRENCY"] = $_POST["CURRENCY"];
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (m.materialName LIKE :Q_LIKE OR s.supplierName LIKE :Q_LIKE OR m.shortCode LIKE :Q_LIKE OR m.catalogNo LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            $totalRecords = (int) $db->createQuery("SELECT COUNT(m.materialId) " . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; DQL'e eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = array("m.materialName", "c.sortNo", "s.supplierName", "p.packageQty", "p.packagePrice", "unitCostTry");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "m.materialName";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";

            $rows = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            // Birim maliyet (TL) = ambalaj fiyatı × kur ÷ (hasta sayısı ya da ambalaj içeriği); TL'nin kuru öngörü katsayısı.
            // Fiyat ya da kur yoksa boş. usedCount: malzemenin bağlı olduğu test sayısı (kitte "kaç teste bağlı")
            $dtResponse = $db->createQuery("
                SELECT m.materialId AS m_materialId, m.materialName AS m_materialName, m.usageUnit AS m_usageUnit, m.note AS m_note, m.shortCode AS m_shortCode, m.catalogNo AS m_catalogNo, m.billablePrice AS m_billablePrice,
                       c.categoryId AS c_categoryId, c.categoryCode AS c_categoryCode, c.categoryName AS c_categoryName, c.categoryNameEn AS c_categoryNameEn,
                       s.supplierName AS s_supplierName,
                       p.materialPriceId AS p_materialPriceId, p.packageQty AS p_packageQty, p.patientQty AS p_patientQty,
                       p.packagePrice AS p_packagePrice, p.currency AS p_currency, p.packageDesc AS p_packageDesc,
                       COALESCE(sr.rate, r.rate) AS r_rate, (CASE WHEN sr.rate IS NULL THEN 0 ELSE 1 END) AS supplierRate,
                       (CASE WHEN (c.categoryCode = 'BILLABLE' AND m.billablePrice IS NOT NULL) THEN m.billablePrice
                             ELSE (CASE WHEN p.currency = 'TRY' THEN p.packagePrice * :TRY_FACTOR ELSE p.packagePrice * COALESCE(sr.rate, r.rate) END)
                                  / (CASE WHEN p.patientQty > 0 THEN p.patientQty ELSE p.packageQty END) END) AS unitCostTry,
                       (SELECT COUNT(ri.recipeItemId) FROM BIO\MLYT\RecipeItem ri
                         WHERE ri.material = m AND ri.recordFl > 0 AND ri.activeFl = 'Y') AS usedCount
            " . $from . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", m.materialId ASC")
                ->setParameters($params + array("TRY_FACTOR" => $study ? $study->getTryFactor() : "1"))
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

            foreach ($dtResponse as &$r) {
                // Bedelli malzeme: sabit bedel (TL / birim); çalışma fiyatına bakılmaz
                $billable = $r["c_categoryCode"] === MaterialCategory::CODE_BILLABLE;
                $hasPrice = $billable ? $r["m_billablePrice"] !== null : $r["p_materialPriceId"] !== null;
                $r["isBillable"] = $billable ? "Y" : "N";
                if ($sessionLang === "en" && $r["c_categoryNameEn"]) { $r["c_categoryName"] = $r["c_categoryNameEn"]; }
                unset($r["c_categoryNameEn"]);
                $r["m_usageUnitName"] = MT_UNIT_NAMES[$r["m_usageUnit"]][$sessionLang === "en" ? 1 : 0] ?? $r["m_usageUnit"];
                $r["hasPrice"]        = $hasPrice ? "Y" : "N";
                $r["rateMissing"]     = ($hasPrice && !$billable && $r["p_currency"] !== StudyRate::CURRENCY_TRY && $r["r_rate"] === null) ? "Y" : "N";
                $r["unitCostTry"]     = $r["unitCostTry"] !== null ? round((float) $r["unitCostTry"], 4) : null;
                $r["isKit"]           = $r["c_categoryCode"] === MaterialCategory::CODE_KIT ? "Y" : "N";
                $r["usedCount"]       = (int) $r["usedCount"];
            }
            unset($r);

            $kit = $db->getRepository(MaterialCategory::class)->findOneBy(array("categoryCode" => MaterialCategory::CODE_KIT));
            $resObject = array(
                "kitCategoryId" => $kit?->getCategoryId(),   // formda kit alanları bu kategori seçilince açılır
                "billableCategoryId" => $db->getRepository(MaterialCategory::class)->findOneBy(array("categoryCode" => MaterialCategory::CODE_BILLABLE))?->getCategoryId(),
                "study"  => $study ? mtStudyToArray($study, mtRates($db, $study), mtSupplierRates($db, $study)) : null,
                "counts" => array("all" => (int) $cnt["allCount"], "priced" => (int) $cnt["pricedCount"],
                                  "missing" => (int) $cnt["allCount"] - (int) $cnt["pricedCount"]),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Excel çıktısı: ekrandaki filtrelerle (STUDY_ID, TAB, CATEGORY_ID, SUPPLIER_ID, Q); indirme adresi döner (/api/pdf.php)
    case "getExcelList":
        $execType = ET_CUSTOM;
        try{
            $study = mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            $export = new \Biolab\Core\Mlyt\MaterialExport($db, $companyId, $study, array(
                "tab"        => (string) ($_POST["TAB"] ?? "all"),
                "categoryId" => (int) ($_POST["CATEGORY_ID"] ?? 0),
                "supplierId" => (int) ($_POST["SUPPLIER_ID"] ?? 0),
                "q"          => (string) ($_POST["Q"] ?? ""),
                "currency"   => (string) ($_POST["CURRENCY"] ?? ""),
            ));
            $fileName = $export->fileName();
            $token = \Biolab\Core\Http\PdfHandoff::stash($export->bytes(), $fileName,
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            $resObject = array("url" => "/api/pdf.php?t=" . $token, "fileName" => $fileName);
            $resCode = 0;
            $resMsg = "Excel hazır";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Satır seçilince formu dolduracak kayıt (malzeme + seçili çalışmadaki fiyatı)
    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $material = mtFindMaterial($db, (int) ($_POST["MATERIAL_ID"] ?? 0), $companyId);
            if ($material === null) { $resCode = 20; $resMsg = "Malzeme bulunamadı."; break; }
            $study = mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            $price = $study ? $db->getRepository(MaterialPrice::class)->findOneBy(array("study" => $study, "material" => $material)) : null;

            $dtResponse = mtMaterialToArray($material, $price, $study ? mtRates($db, $study, $material->getSupplier()) : array(), $sessionLang);
            $dtResponse["tests"] = mtMaterialTests($db, $material, $sessionLang);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setMaterial":
        $execType = ET_CUSTOM;
        try{
            $name     = trim((string) ($_POST["MATERIAL_NAME"] ?? ""));
            $category = mtFindCategory($db, (int) ($_POST["CATEGORY_ID"] ?? 0));
            $unit     = (string) ($_POST["USAGE_UNIT"] ?? "");
            $note     = isset($_POST["NOTE"]) ? trim((string) $_POST["NOTE"]) : null;
            // Kısa kod (patoloji: TK, L, LM...) Türkçe büyük harf; katalog no tedarikçinin ürün kodu
            $shortCode = mb_strtoupper(str_replace(array("i", "ı"), array("İ", "I"), trim((string) ($_POST["SHORT_CODE"] ?? ""))), "UTF-8");
            $shortCode = $shortCode !== "" ? $shortCode : null;
            $catalogNo = trim((string) ($_POST["CATALOG_NO"] ?? "")) ?: null;

            // Fiyat: üç alan da boşsa bu çalışmada fiyat yok demektir (varsa silinir)
            $pkgQty   = mtDecimal($_POST["PACKAGE_QTY"] ?? null);
            $pkgPrice = mtDecimal($_POST["PACKAGE_PRICE"] ?? null);
            $currency = (string) ($_POST["CURRENCY"] ?? StudyRate::CURRENCY_TRY);
            $hasPrice = $pkgQty !== null || $pkgPrice !== null;
            // Bir ambalajdan çıkan hasta sayısı (isteğe bağlı; kit, standart). Boşsa bölen ambalaj içeriğidir.
            $patientQty = mtDecimal($_POST["PATIENT_QTY"] ?? null);
            // Bedelli malzeme: bir kere tanımlanan sabit bedel (TL / birim); çalışmanın fiyatı değiştirilmez
            $billable = $category !== null && $category->getCategoryCode() === MaterialCategory::CODE_BILLABLE;
            $billablePrice = $billable ? mtDecimal($_POST["BILLABLE_PRICE"] ?? null) : null;
            if ($billable) { $hasPrice = false; }

            $errors = array();
            if ($billable && trim((string) ($_POST["BILLABLE_PRICE"] ?? "")) !== "" && ($billablePrice === null || (float) $billablePrice < 0)) {
                $errors[] = "Bedeli sayı olarak girin.";
            }
            if ($name === "") { $errors[] = "Malzeme adını girin."; }
            if ($category === null) { $errors[] = "Kategori seçin."; }
            if (!in_array($unit, MT_UNITS, true)) { $errors[] = "Kullanım birimi seçin."; }
            if ($patientQty !== null && (float) $patientQty <= 0) { $errors[] = "Hasta sayısı sıfırdan büyük olmalı."; }
            if ($shortCode !== null && mb_strlen($shortCode) > 10) { $errors[] = "Kısa kod en çok 10 karakter olmalı."; }
            if ($catalogNo !== null && mb_strlen($catalogNo) > 30) { $errors[] = "Katalog no en çok 30 karakter olmalı."; }
            if ($hasPrice) {
                if ($pkgQty === null || (float) $pkgQty <= 0) { $errors[] = "Ambalaj içeriği sıfırdan büyük olmalı."; }
                if ($pkgPrice === null || (float) $pkgPrice < 0) { $errors[] = "Ambalaj fiyatını girin."; }
                if (!in_array($currency, MT_PRICE_CURRENCIES, true)) { $errors[] = "Para birimi seçin."; }
            }
            if ($errors) {
                $resCode = 10;
                $resMsg = implode(" ", $errors);
                break;
            }

            $study = mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            if ($hasPrice && $study === null) { $resCode = 20; $resMsg = "Fiyat girmek için önce bir maliyet çalışması seçin."; break; }
            if ($study !== null && !$study->isEditable()) { $resCode = 30; $resMsg = "Onaylanmış çalışmanın fiyatları değiştirilemez."; break; }

            // Aynı adla ikinci malzeme açılmasın (mükerrerler zaten Excel'den sorun olarak geliyor)
            $materialId = (int) ($_POST["MATERIAL_ID"] ?? 0);
            $same = $db->createQuery("
                SELECT COUNT(m.materialId) FROM BIO\MLYT\Material m
                 WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0
                   AND m.materialName = :NAME AND m.materialId <> :ID
            ")->setParameters(array("COMPANY_ID" => $companyId, "NAME" => $name, "ID" => $materialId))->getSingleScalarResult();
            if ((int) $same > 0) { $resCode = 40; $resMsg = "Bu adla bir malzeme zaten var."; break; }
            // Kısa kod tekil: patoloji testinde "2 TK" hangi malzeme olduğu belli olsun
            if ($shortCode !== null) {
                $other = $db->createQuery("
                    SELECT m.materialName FROM BIO\MLYT\Material m
                     WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0 AND m.shortCode = :CODE AND m.materialId <> :ID
                ")->setParameters(array("COMPANY_ID" => $companyId, "CODE" => $shortCode, "ID" => $materialId))->setMaxResults(1)->getOneOrNullResult();
                if ($other) { $resCode = 40; $resMsg = "'" . $shortCode . "' kısa kodu zaten '" . $other["materialName"] . "' malzemesinde."; break; }
            }

            if ($materialId > 0) {
                $material = mtFindMaterial($db, $materialId, $companyId);
                if ($material === null) { $resCode = 20; $resMsg = "Malzeme bulunamadı."; break; }
                $material->setMaterialName($name)->setCategory($category)->setUsageUnit($unit);
            } else {
                $material = new Material($name, $category, $unit);
                $material->setCompany($db->getReference(Company::class, $companyId));
                $db->persist($material);
            }
            // Alan gönderilmediyse (eski önbellekli ekran) mevcut kısa kod / katalog no korunur
            if (array_key_exists("SHORT_CODE", $_POST)) { $material->setShortCode($shortCode); }
            if (array_key_exists("CATALOG_NO", $_POST)) { $material->setCatalogNo($catalogNo); }
            $material->setNote($note)
                     ->setBillablePrice($billablePrice)
                     ->setSupplier(mtSupplier($db, $companyId, $_POST["SUPPLIER_ID"] ?? null, $_POST["SUPPLIER_NAME"] ?? null));

            // Bedelli malzemede çalışma fiyatına dokunulmaz (kategori yanlışlıkla değişirse fiyat kaybolmasın)
            if ($study !== null && !$billable) {
                $price = $material->getMaterialId()
                    ? $db->getRepository(MaterialPrice::class)->findOneBy(array("study" => $study, "material" => $material))
                    : null;
                if ($hasPrice) {
                    if ($price === null) {
                        $price = new MaterialPrice($study, $material, $pkgQty, $currency, $pkgPrice);
                        $db->persist($price);
                    } else {
                        $price->setPackageQty($pkgQty)->setCurrency($currency)->setPackagePrice($pkgPrice);
                    }
                    $price->setPackageDesc(isset($_POST["PACKAGE_DESC"]) ? trim((string) $_POST["PACKAGE_DESC"]) : null)
                          ->setPatientQty($patientQty)
                          ->setVatIncluded(($_POST["VAT_INCLUDED"] ?? "N") === "Y");
                } elseif ($price !== null) {
                    $db->remove($price);
                }
            }
            $db->flush();

            $resObject = array("materialId" => $material->getMaterialId());
            $resCode = 0;
            $resMsg = "Malzeme kaydedildi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setMaterialDelete":
        $execType = ET_CUSTOM;
        try{
            $material = mtFindMaterial($db, (int) ($_POST["MATERIAL_ID"] ?? 0), $companyId);
            if ($material === null) { $resCode = 20; $resMsg = "Malzeme bulunamadı."; break; }
            $material->softDelete();
            $db->flush();
            $resCode = 0;
            $resMsg = "Malzeme silindi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Malzemeyi (kiti) teste bağlar: reçeteye hasta başı satır. Aynı test zaten bağlıysa miktarı güncellenir.
    //   resObject: tests (güncel bağlı testler)
    case "setMaterialTest":
        $execType = ET_CUSTOM;
        try{
            $material = mtFindMaterial($db, (int) ($_POST["MATERIAL_ID"] ?? 0), $companyId);
            if ($material === null) { $resCode = 20; $resMsg = "Önce malzemeyi kaydedin."; break; }
            $testId = (int) ($_POST["TEST_ID"] ?? 0);
            $test = $testId > 0 ? $db->getRepository(Test::class)->findOneBy(array("testId" => $testId, "company" => $companyId)) : null;
            if ($test === null || $test->isDeleted()) { $resCode = 20; $resMsg = "Test seçin."; break; }
            if ($test->getCostMethod() === Test::METHOD_CALCULATED) {
                $resCode = 30; $resMsg = "Hesaplamalı testin maliyeti bileşenlerinden gelir; malzeme bağlanmaz."; break;
            }
            $qty = mtDecimal($_POST["QUANTITY"] ?? "1");
            if ($qty === null || (float) $qty <= 0) { $resCode = 10; $resMsg = "Miktar sıfırdan büyük olmalı."; break; }

            $item = $db->getRepository(RecipeItem::class)->findOneBy(array("test" => $test, "material" => $material, "consumption" => RecipeItem::PER_PATIENT));
            if ($item === null) {
                // Testin reçetesinde en sona eklenir; kit genelde tek satırdır
                $maxSort = (int) $db->createQuery("SELECT MAX(ri.sortNo) FROM BIO\MLYT\RecipeItem ri WHERE ri.test = :TEST")
                    ->setParameter("TEST", $test)->getSingleScalarResult();
                $db->persist(new RecipeItem($test, $material, $qty, RecipeItem::PER_PATIENT, $maxSort + 10));
                $resMsg = $test->getTestCode() . " bağlandı";
            } else {
                $item->setQuantity($qty);
                $resMsg = $test->getTestCode() . " miktarı güncellendi";
            }
            $db->flush();

            $resObject = array("tests" => mtMaterialTests($db, $material, $sessionLang));
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Test bağlantısını kaldırır (reçete satırı silinir; test maliyeti ekranındaki reçete kaydıyla aynı davranış)
    case "setMaterialTestDelete":
        $execType = ET_CUSTOM;
        try{
            $material = mtFindMaterial($db, (int) ($_POST["MATERIAL_ID"] ?? 0), $companyId);
            $item = $db->find(RecipeItem::class, (int) ($_POST["RECIPE_ITEM_ID"] ?? 0));
            if ($material === null || $item === null || $item->getMaterial() !== $material) {
                $resCode = 20; $resMsg = "Bağlantı bulunamadı."; break;
            }
            $code = $item->getTest()->getTestCode();
            $db->remove($item);
            $db->flush();

            $resObject = array("tests" => mtMaterialTests($db, $material, $sessionLang));
            $resCode = 0;
            $resMsg = $code . " bağlantısı kaldırıldı";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    default:
        $resCode = 9000;
        $resMsg = "Bilinmeyen işlem: " . $query;
}

/* ---------- Yardımcılar ---------- */

function mtFindStudy($db, int $studyId, int $companyId): ?Study
{
    if ($studyId <= 0) { return null; }
    $s = $db->getRepository(Study::class)->findOneBy(array("studyId" => $studyId, "company" => $companyId));
    return ($s !== null && !$s->isDeleted()) ? $s : null;
}

/** Kayıtta kullanılabilecek kategori: aktif ve silinmemiş */
function mtFindCategory($db, int $categoryId): ?MaterialCategory
{
    if ($categoryId <= 0) { return null; }
    $c = $db->find(MaterialCategory::class, $categoryId);
    return ($c !== null && $c->isActive() && !$c->isDeleted()) ? $c : null;
}

function mtFindMaterial($db, int $materialId, int $companyId): ?Material
{
    if ($materialId <= 0) { return null; }
    $m = $db->getRepository(Material::class)->findOneBy(array("materialId" => $materialId, "company" => $companyId));
    return ($m !== null && !$m->isDeleted()) ? $m : null;
}

/**
 * Çalışmanın kurları: ["USD" => "55.0000", ...] ; TRY'nin kuru öngörü katsayısı (varsayılan 1).
 * Tedarikçi verilirse onun bu çalışmadaki sözleşme kurları genel kurun yerine geçer (ör. NUCLEUS EUR 44,7).
 */
function mtRates($db, Study $study, ?Supplier $supplier = null): array
{
    $rates = array(StudyRate::CURRENCY_TRY => $study->getTryFactor());
    foreach ($db->getRepository(StudyRate::class)->findBy(array("study" => $study)) as $r) {
        $rates[$r->getCurrency()] = $r->getRate();
    }
    if ($supplier !== null) {
        foreach ($db->getRepository(StudySupplierRate::class)->findBy(array("study" => $study, "supplier" => $supplier)) as $r) {
            if (!$r->isDeleted()) { $rates[$r->getCurrency()] = $r->getRate(); }
        }
    }
    return $rates;
}

/** Çalışmanın tedarikçi sözleşme kurları: [{supplierId, supplierName, currency, rate}] (çalışma çubuğu ve penceresi için) */
function mtSupplierRates($db, Study $study): array
{
    return $db->createQuery("
        SELECT s.supplierId, s.supplierName, r.currency, r.rate
          FROM BIO\MLYT\StudySupplierRate r
          JOIN r.supplier s
         WHERE r.study = :STUDY AND r.recordFl > 0
         ORDER BY s.supplierName, r.currency
    ")->setParameter("STUDY", $study)->getArrayResult();
}

/** Seçilen tedarikçi; listede yoksa yazılan adla yenisi açılır */
function mtSupplier($db, int $companyId, $supplierId, $supplierName): ?Supplier
{
    if ($supplierId !== null && (int) $supplierId > 0) {
        return $db->getRepository(Supplier::class)->findOneBy(array("supplierId" => (int) $supplierId, "company" => $companyId));
    }
    $name = trim((string) $supplierName);
    if ($name === "") { return null; }
    $s = $db->getRepository(Supplier::class)->findOneBy(array("supplierName" => $name, "company" => $companyId));
    if ($s === null) {
        $s = new Supplier($name);
        $s->setCompany($db->getReference(Company::class, $companyId));
        $db->persist($s);
    }
    return $s;
}

/** "1.234,56" / "1234,56" / "1234.56" → "1234.56" ; boşsa null */
function mtDecimal($v): ?string
{
    if ($v === null) { return null; }
    $v = str_replace(array(" ", "\u{00A0}"), "", trim((string) $v));
    if ($v === "") { return null; }
    if (str_contains($v, ",")) {
        $v = str_replace(array(".", ","), array("", "."), $v);
    }
    return is_numeric($v) ? $v : null;
}

/** Malzemenin bağlı olduğu testler (reçete satırları): test kodu, adı, miktar, tüketim */
function mtMaterialTests($db, Material $m, string $lang): array
{
    $consumption = array(RecipeItem::PER_PATIENT => array("hasta başı", "per patient"), RecipeItem::PER_RUN => array("çalışma başı", "per run"),
                         RecipeItem::PER_DAY => array("gün başı", "per day"), RecipeItem::PER_WEEK => array("hafta başı", "per week"),
                         RecipeItem::PER_MONTH => array("ay başı", "per month"));
    $rows = $db->createQuery("
        SELECT ri.recipeItemId, ri.quantity, ri.consumption, t.testId, t.testCode, t.testName, t.activeFl
          FROM BIO\MLYT\RecipeItem ri
          JOIN ri.test t
         WHERE ri.material = :MATERIAL AND ri.recordFl > 0 AND ri.activeFl = 'Y' AND t.recordFl > 0
         ORDER BY t.testCode
    ")->setParameter("MATERIAL", $m)->getArrayResult();
    foreach ($rows as &$r) {
        $r["consumptionName"] = $consumption[$r["consumption"]][$lang === "en" ? 1 : 0] ?? $r["consumption"];
    }
    unset($r);
    return $rows;
}

function mtStudyToArray(Study $s, array $rates, array $supplierRates = array()): array
{
    return array(
        "studyId"          => $s->getStudyId(),
        "studyName"        => $s->getStudyName(),
        "rateDate"         => $s->getRateDate()->format("d.m.Y"),
        "status"           => $s->getStatus(),
        "editable"         => $s->isEditable(),
        "workDaysPerMonth" => $s->getWorkDaysPerMonth(),
        "tryFactor"        => $s->getTryFactor(),
        "supplierRates"    => $supplierRates,   // [{supplierId, supplierName, currency, rate}]
        "rates"            => array_diff_key($rates, array(StudyRate::CURRENCY_TRY => 1)),
    );
}

function mtMaterialToArray(Material $m, ?MaterialPrice $p, array $rates, string $lang): array
{
    $unitCost = null;
    $rateMissing = false;
    if ($p !== null) {
        if (isset($rates[$p->getCurrency()])) {
            // Ekranda gösterim için; maliyet hesabı (Core\Mlyt\CostCalculator) aynı formülü kullanır
            $divisor = (float) $p->getPatientQty() > 0 ? (float) $p->getPatientQty() : (float) $p->getPackageQty();
            $unitCost = round((float) $p->getPackagePrice() * (float) $rates[$p->getCurrency()] / $divisor, 4);
        } else {
            $rateMissing = true;
        }
    }
    return array(
        "materialId"   => $m->getMaterialId(),
        "materialName" => $m->getMaterialName(),
        "categoryId"   => $m->getCategory()->getCategoryId(),
        "categoryCode" => $m->getCategory()->getCategoryCode(),
        "categoryName" => $m->getCategory()->getName($lang),
        "usageUnit"    => $m->getUsageUnit(),
        "usageUnitName"=> MT_UNIT_NAMES[$m->getUsageUnit()][$lang === "en" ? 1 : 0] ?? $m->getUsageUnit(),
        "supplierId"   => $m->getSupplier()?->getSupplierId(),
        "supplierName" => $m->getSupplier()?->getSupplierName(),
        "sourceRowNo"  => $m->getSourceRowNo(),
        "note"         => $m->getNote(),
        "shortCode"    => $m->getShortCode(),
        "catalogNo"    => $m->getCatalogNo(),
        "isKit"        => $m->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT,
        "isBillable"   => $m->isBillable(),
        "billablePrice"=> $m->getBillablePrice(),
        "hasPrice"     => $p !== null,
        "packageDesc"  => $p?->getPackageDesc(),
        "packageQty"   => $p?->getPackageQty(),
        "patientQty"   => $p?->getPatientQty(),
        "priceNote"    => $p?->getNote(),
        "currency"     => $p?->getCurrency(),
        "packagePrice" => $p?->getPackagePrice(),
        "vatIncluded"  => $p !== null && $p->isVatIncluded(),
        "unitCostTry"  => $unitCost,
        "rateMissing"  => $rateMissing,
    );
}
