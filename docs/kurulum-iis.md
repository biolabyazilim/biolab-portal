# Biolab Portal (HbRapor) — IIS sunucu kurulumu

Sunucu: SRVAPPYZLM (WIN-B89G7GU0A0H). SIBO aynı sunucuda IIS'te (`biolab.sibo`, port 8090).

## Tamamlananlar (23.10.2026)

- XAMPP (Apache + PHP 8.2) kaldırıldı. 8080 portunu kullanıyordu ve PHP 8.2 projeyi çalıştıramaz.
- PHP 8.5.12 **NTS x64**, `C:\PHP\8.5` klasörüne kuruldu.
  - `C:\PHP\temp` ve `C:\PHP\logs` klasörleri oluşturuldu.
  - Eklentiler: curl, fileinfo, gd, intl, mbstring, openssl, zip, sqlsrv, pdo_sqlsrv (5.13.0, 8.5 NTS x64).
- IIS'e CGI özelliği eklendi.
  - Sunucu düzeyinde Handler Mapping: `*.php` → `C:\PHP\8.5\php-cgi.exe`.
  - FastCGI ayarları: Activity ve Request Timeout 300; MaxRequests 10000.
  - Ortam değişkenleri: `PHPRC=C:\PHP\8.5`, `PHP_FCGI_MAX_REQUESTS=10000`.
  - Standard error mode: `ReturnGeneric500`.
- Uygulama havuzu `biolab.portal` oluşturuldu (No Managed Code, Integrated).
- Site `biolab.portal` açıldı: fiziksel yol `C:\inetpub\biolab-portal\public`, `http :8080`.
- `test.php` ile PHP 8.5 ve sqlsrv'nin çalıştığı görüldü. **Test dosyası silinmeli.**

`php.ini`'de geliştirme bilgisayarından farklı olan satırlar:

| Ayar | Değer |
|---|---|
| `extension_dir` | `C:\PHP\8.5\ext` |
| `error_log` | `C:\PHP\logs\php_errors.log` |
| `sys_temp_dir` / `upload_tmp_dir` / `session.save_path` | `C:\PHP\temp` |
| `cgi.force_redirect` | `0` |
| `cgi.fix_pathinfo` | `1` |
| `fastcgi.impersonate` | `1` |

## 1. Klasör izinleri

`C:\PHP\temp` ve `C:\PHP\logs` klasörlerine **IIS_IUSRS** için **Değiştirme (Modify)** izni verin:
Sağ tık → Özellikler → Güvenlik → Düzenle → Ekle → `IIS_IUSRS`.

Bu izin yoksa giriş yapılamaz (oturum yazılamaz) ve rapor önizlemesi açılmaz.

## 2. Proje dosyalarını kopyalama

Hedef: `C:\inetpub\biolab-portal\` (proje kökü). Site yalnızca bunun içindeki `public` klasörünü gösterir.

**Kopyalanacaklar** (geliştirme bilgisayarındaki `htdocs\biolab-portal` klasöründen):

```
bootstrap.php   cli-config.php   composer.json   composer.lock
config.php      migrations.php   migration.bat
mlyt-import.php mlyt-import.bat                     (maliyet: Excel aktarımı)
core\   docs\   lib\   model\   public\   vendor\
```

**Kopyalanmayacaklar:**

| Dosya / klasör | Neden |
|---|---|
| `.idea\`, `.gitignore` | Geliştirme aracı dosyaları |
| `lib\php\proxy\db.inc-orj.php` | Eski D.Sys veritabanı bilgileri içeriyor |
| `lib\php\proxy\db.settings-notuse.php` | Eski D.Sys veritabanı bilgileri içeriyor |
| `public\_legacy\` | Eski D.Sys ekranı; kullanılmıyor |
| `public\.htaccess` | Yalnız Apache için; IIS'te `public\web.config` kullanılır (zararsız, ama gereksiz) |

Kopyaladıktan sonra şunlar mevcut olmalı:
- `C:\inetpub\biolab-portal\public\index.html`
- `C:\inetpub\biolab-portal\public\web.config`

## 3. `config.php` (sunucudaki kopyada değiştirilecekler)

| Ayar | Geliştirme | Sunucu |
|---|---|---|
| `db.host` | `localhost\SQLEXPRESS` | Sunucudaki SQL Server örneği (ör. `localhost\SQLEXPRESS`) |
| `db.user` / `db.password` | geliştirme kullanıcısı | Sunucudaki uygulama kullanıcısı (`biolab_portal_app`) |
| `dev_mode` | `true` | **`false`** |
| `d10.url` | `http://192.168.1.20/Dia/diaweb.cgi` | Aynı (sunucu cihaza erişebilmeli) |
| `hb_report.lab_name` / `lab_lines` | Biolab / Tıbbi Laboratuvar | Laboratuvarın resmi adı, adresi, telefonu |

Kontrol: sunucuda tarayıcıyla `http://192.168.1.20/Dia/diaweb.cgi` açılıyorsa D-10 erişimi var demektir.

## 4. Veritabanı

**A) Geliştirmedeki veritabanını taşımak** (kullanıcılar, yorum blokları ve kayıtlar birlikte gelir):
1. Geliştirme bilgisayarında SSMS → `Biolab_PORTAL` → sağ tık → Tasks → **Back Up...** → `.bak` dosyası.
2. Dosyayı sunucuya kopyalayın.
3. Sunucuda SSMS → Databases → sağ tık → **Restore Database...**
4. Sunucuda `biolab_portal_app` SQL kullanıcısını oluşturun (SQL Server Authentication) ve veritabanında `db_owner` yapın.
   Geri yüklenen veritabanındaki eski kullanıcı ile eşleştirmek gerekirse:
   `ALTER USER biolab_portal_app WITH LOGIN = biolab_portal_app;`

**B) Boş veritabanı:** Sunucuda `Biolab_PORTAL` veritabanını ve kullanıcıyı oluşturup proje kökünde şu komutu çalıştırın:

```
C:\PHP\8.5\php.exe vendor\bin\doctrine-migrations migrate --no-interaction
```

Ardından yönetici kullanıcı, şirket ve kullanıcı-şirket kaydı eklenmeli.

## 5. Kontroller

1. `http://localhost:8080/` → portal giriş ekranı açılmalı.
2. Giriş yapın → HB Elektroforez → İş listesi → "Cihazdan şimdi çek" → D-10 sonuçları gelmeli.
3. Uzman onay ekranında "Rapor önizleme" → PDF yeni sekmede `11714240-…-TASLAK.pdf` adıyla açılmalı.
4. Sorun olursa ilk bakılacak yer: `C:\PHP\logs\php_errors.log`.
5. Başka bir bilgisayardan `http://192.168.1.43:8080/` açılmalı.
   Açılmazsa sunucu güvenlik duvarında 8080 için gelen kuralı gerekir:
   Windows Defender Firewall → Gelişmiş ayarlar → Gelen kurallar → Yeni kural → Bağlantı noktası → TCP 8080.

## Karşılaşılan sorunlar ve çözümleri

| Belirti | Sebep | Çözüm |
|---|---|---|
| `localhost:8080`'de Apache / PHP 8.2 cevap veriyor | XAMPP'in Apache'si 8080'de çalışıyordu | XAMPP kaldırıldı |
| Girişte "Beklenmeyen bir hata oluştu" | `config.php`'deki SQL örneği (`localhost\SQLEXPRESS`) ve `biolab_portal_app` kullanıcısı sunucuda yoktu | Örnek adı `localhost\BIOLAB`; kullanıcı SSMS'te oluşturuldu (`db_owner`) |
| Konsolda `405 Method Not Allowed`, ardından `Cannot read properties of null (reading 'BARCODE')` | `lib.js` dil dosyalarını (.json) POST ile istiyordu. IIS düz dosyaya POST'u kabul etmez (Apache eder). | `lib.js` 3 yerde GET yapıldı, sürüm `?v=1.1.2` (26.10.2026) |
| Komut satırında PHP hiçbir şey yazmıyor | `display_errors = Off` | Denemede `php.exe -d display_errors=1 ...` kullanılır; hatalar `C:\PHP\logs\php_errors.log` dosyasında |

## 6. Maliyet uygulaması (biolab-maliyet)

`migration.bat` ve `mlyt-import.bat` PHP'yi kendileri bulur: `C:\PHP\8.5\php.exe` varsa onu (sunucu), yoksa
geliştirme PHP'sini kullanır; menünün üstünde hangi PHP'nin kullanıldığı yazar. Hatalar komut satırında görünür
(`-d display_errors=1`).

1. **Tablolar:** proje kökünde `migration.bat` → **3** (önizleme) → **4** (uygula).
   **2 (diff)'i seçmeyin**: migration dosyaları hazır gelir; diff aynı tabloları ikinci kez üretir.
   (Bekleyen migration varken 2 artık çalışmaz, uyarı verir.)
2. **Test ve malzeme listesi (tek seferlik):** `mlyt-import.bat` → **3** ile Excel'in sunucudaki yolunu girin
   (`mlyt programı.xlsx`) → **1** (deneme, rapor) → **2** (uygula).
   Veritabanı geliştirmeden yedekle taşındıysa (4-A) bu adım gerekmez; veriler zaten gelir.
3. **Kullanıcı ve rol:** kullanıcı `mng_user`, şirket `mng_user_company`, rol `mng_user_role` (`MLYT_EXPERT`
   maliyet uzmanı, `MLYT_MANAGER` yönetici, `MLYT_LAB` lab sorumlusu, `HB_USER` HB Elektroforez).
   Rolün uygulaması `mng_role.APP_CODE`'dadır; kullanıcı bir uygulamaya ancak o uygulamanın rolü varsa girer, yoksa
   "Bu uygulama için yetkiniz bulunmamaktadır" görür (bkz. 6.1). Şifre `PASSWORD_HASH` alanına düz yazılmaz; `password_hash()` çıktısı yazılır:
   `C:\PHP\8.5\php.exe -r "echo password_hash('SIFRE', PASSWORD_DEFAULT);"`
4. Kontrol: `http://localhost:8080/apps/biolab-maliyet/` → giriş → menüde Tanımlar → Malzemeler, Test tanımları.

**Maliyet için sunucuya kopyalanacak dosyalar** (proje köküne göre; klasör yoksa oluşturulur):

| Klasör | Dosyalar |
|---|---|
| kök | `migration.bat`, `mlyt-import.bat`, `mlyt-import.php` |
| `core\Auth\` | `AuthService.php`, `FormAccess.php` |
| `core\Mlyt\` | `ExcelImport.php`, `XlsxReader.php`, `CostCalculator.php` (maliyet hesabı), `XlsxWriter.php`, `PathologyExport.php` (Excel çıktısı) |
| `lib\php\proxy\` | `jsonp.php` (ekran yetki kontrolü) |
| `model\Entity\BIO\MLYT\` | tüm dosyalar |
| `model\Entity\BIO\MNG\` | `Form.php`, `Menu.php`, `Role.php`, `RoleForm.php`, `UserRole.php` |
| `model\migration\` | `Version20261024*.php`, `Version20261026*.php` |
| `public\api\` | `auth.php` (menü API'si) |
| `public\apps\biolab-maliyet\` | tüm klasör |
| `public\lib\app\css\` | **`portal-menu.css`** |
| `public\lib\app\js\` | **`portal-menu.js`**, `lib.js` |
| `public\lib\app\resources\tr\` ve `en\` | `material\`, `test\`, `test_cost\` klasörleri |

### 6.1 Uygulama yetkisi (27.10.2026)

Yetki iki katmanlı: **uygulama** (`mng_role.APP_CODE`: kullanıcı uygulamaya girebilir mi) ve **ekran**
(`mng_role_form`: menüde / sekmelerde hangi ekranları görür, hangi ekranın PHP'sine istek atabilir). İki katman
maliyette ve HB'de birlikte çalışır.

Uygulamaya giriş iki yoldan biriyle açılır: rolün `APP_CODE`'u o uygulamadır (`HB_USER`, `MLYT_*`), ya da role
`mng_role_form` ile o uygulamanın en az bir ekranı verilmiştir. Böylece tek rol (ör. `MANAGEMENT`) birden çok
uygulamanın ekranlarını kapsayabilir; her uygulamada yalnız kendisine verilen ekranları görür.

İlk kontrol girişte: uygulamanın giriş ekranı uygulama kodunu da gönderir; şifre doğru olsa bile o uygulamanın rolü
yoksa oturum açılmaz, "Bu uygulama için yetkiniz olmadığından giriş yapamazsınız" denir. Oturum portalda ortak
olduğu için başka uygulamadan girmiş kullanıcı maliyete geçerse açılışta aynı uyarı paneli çıkar (ikinci kontrol).

Migration `Version20261027100000`: `APP_CODE` kolonu; `MLYT_*` rolleri → `biolab-maliyet`; `HB_USER` rolü
(`hb-elektroforez`) açılır ve **mevcut bütün aktif kullanıcılara verilir** (HB'yi bugün kullanan kilitlenmesin).

Migration `Version20261027140000`: HB ekranları `mng_form`'a (`HB_DASHBOARD`, `HB_SAMPLE_ACCEPTANCE`, `HB_WORK_LIST`,
`HB_REPORT`, `HB_REPORT_REVIEW`), sekmeleri `mng_menu`'ye (kök `hb-elektroforez`) eklenir; `HB_USER` hepsini görür.
Yetkisi olmayan sekme gizlenir. Ayarlar sekmesinin ekranı henüz yok, bu yüzden kimsede görünmez.
Bu migration ile `FormAccess.php` ve HB `js\app.js` birlikte gider: dosyalar migration'sız kopyalanırsa HB'de
bütün istekler "ekran yetkisi yok" ile reddedilir.

**Migration ve aşağıdaki dosyalar birlikte gider.** Dosyalar kopyalanıp migration uygulanmazsa sunucu `APP_CODE`
kolonunu bulamaz ve iki uygulamada da hata verir.

| Klasör | Dosyalar |
|---|---|
| `model\Entity\BIO\MNG\` | `Role.php` |
| `model\migration\` | `Version20261027100000.php`, `Version20261027140000.php` |
| `core\Auth\` | `FormAccess.php`, `AuthService.php` |
| `lib\php\proxy\` | `jsonp.php` |
| `public\api\` | `auth.php` |
| `public\lib\app\js\` ve `css\` | `portal-menu.js`, `portal-menu.css` |
| `public\apps\biolab-maliyet\` | `index.html`, `login.html`, `js\app.js`, `js\login.js` |
| `public\apps\hb-elektroforez\` | `index.html`, `login.html`, `js\app.js`, `js\login.js` |

Yetki ver / al (kullanıcı adı ve rol kodu değiştirilerek):

```sql
-- ver
INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
SELECT u.USER_ID, r.ROLE_ID, uc.COMPANY_ID
  FROM mng_user u JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID
  JOIN mng_role r ON r.ROLE_CODE = 'MLYT_EXPERT'
 WHERE u.USERNAME = 'kullanici.adi';

-- al (o uygulamadaki bütün rolleri). Satır silinir: pasif bırakılırsa aynı rol tekrar verilirken
-- benzersizlik kısıtı (USER_ID, ROLE_ID, COMPANY_ID) INSERT'i reddeder.
DELETE ur
  FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID JOIN mng_user u ON u.USER_ID = ur.USER_ID
 WHERE u.USERNAME = 'kullanici.adi' AND r.APP_CODE = 'biolab-maliyet';
```

Yetki değişikliği kullanıcının bir sonraki açılışında (sayfa yenileme) geçerli olur; yeniden giriş gerekmez.

`portal-menu.js` eksikse uygulama açılışta "Menü yüklenemedi" der (konsolda 404). Arşivle taşınıyorsa arşivi
`public` altına koymayın (web'den indirilebilir) ve iş bitince silin.

### 6.2 Kit ve kit fiyatı (28.10.2026)

Kit ayrı tablo değildir: Malzemeler ekranında **Kit** kategorisinde bir malzemedir (birim: test).
Fiyat bölümünde "Kitteki test sayısı" (ambalaj içeriği) ve "Kit fiyatı" (ambalaj fiyatı) girilir, fiyat diğer
malzemeler gibi seçili çalışmaya yazılır. (İlk sürümdeki "Verim %" 6.3'te "hasta sayısı" ile değişti.)

Kit, pencerenin altındaki **Kullanıldığı testler** bölümünden teste bağlanır; bağlantı o testin reçetesine hasta
başı satırdır (`mlyt_recipe_item`), hemen kaydedilir ve Test maliyetleri ekranında aynı satır görünür. Hesaplamalı
testlere bağlanmaz. Kit yöntemli olup kiti bağlanmamış test, Test maliyetlerinde "Reçete / kit yok" sekmesinde
"Kit bağlanmamış" notuyla listelenir.

Migration `Version20261028063034`: `mlyt_material.YIELD_PCT`. Dosyalar:

| Klasör | Dosyalar |
|---|---|
| `model\Entity\BIO\MLYT\` | `Material.php` |
| `model\migration\` | `Version20261028063034.php` |
| `core\Mlyt\` | `CostCalculator.php`, `PathologyExport.php` (malzeme sayfasına "Verim %" kolonu) |
| `public\apps\biolab-maliyet\material\` | `material-1.0.0.html`, `js\material-1.0.0.js`, `php\material-1.0.0.php` |
| `public\apps\biolab-maliyet\test_cost\` | `testCost-1.0.0.html`, `js\testCost-1.0.0.js` |
| `public\apps\biolab-maliyet\test\` | `test-1.0.0.html`, `js\test-1.0.0.js` |
| `public\lib\app\js\` | `lib.js` (v1.1.5) |
| `public\apps\hb-elektroforez\` | `dashboard\dashboard-1.0.0.html`, `report\report-1.0.0.html`, `report_review\reportReview-1.0.0.html`, `sample_acceptance\sampleAcceptance-1.0.0.html`, `work_list\workList-1.0.0.html` (yalnız `lib.js?v=` sürümü) |

Modal içindeki select'ler `dropdownParent` olmadan kurulur (liste sayfa gövdesine açılır, D.Sys ile aynı);
`dropdownParent` modalın kaydırmasına bağlanıp listeyi açılır açılmaz kapatıyordu.

### 6.3 Hasta sayısı, TL öngörü katsayısı, Excel'den fiyat aktarımı (28.10.2026)

Uzmanın fiyatlı Excel'ine göre (`Kopya mlyt programı.xlsx`, MALZEME LİSTESİ yeni kolonlarla):

- **Hasta sayısı** (`mlyt_material_price.PATIENT_QTY`): bir ambalajdan çıkan hasta (96 testlik kitten 70 hasta; ICP-MS
  standardı için bir şişe kaç hastaya yeter). Doluysa birim maliyet = fiyat × kur ÷ hasta sayısı; boşsa ÷ ambalaj içeriği.
  Malzemeler ekranında fiyat bölümünde ("Kitten çıkan hasta sayısı" / "Kaç hastaya yeter"). `YIELD_PCT` kalktı
  (girilmiş verim varsa migration hasta sayısına çevirir).
- **TL öngörü katsayısı** (`mlyt_study.TRY_FACTOR`, varsayılan 1): TL fiyatlar bununla çarpılır (Excel'de TL kuru 1,5,
  notu "MUTLAKA ÖNGÖRÜLÜ ÇALIŞ"). Çalışma penceresinden girilir; 1'den farklıysa çalışma çubuğunda "TL ×1,5" görünür.
- **Fiyat aktarımı** (`mlyt-import.bat` → 3 ile fiyatlı Excel seçilir → 5 deneme / 6 uygula, çalışma no sorulur):
  MALZEME LİSTESİ'nden seçili çalışmaya fiyat, para birimi, ambalaj içeriği, hasta sayısı ve not yazılır; olmayan
  malzeme açılır, döviz kurları çalışmada boşsa doldurulur, kit tedarikçisi (ALGEN, ANATOLİA GENETİK) açıklamadan
  yazılır. Bölen Excel'in kendi TL birim maliyetinden çıkarılır, böylece her satırın birim maliyeti Excel'le aynı kalır
  (geliştirmede 238/238 satır eşleşti). TL öngörü katsayısı aktarımda değişmez; rapor Excel'deki TL kurlarını gösterir.
  Ekrandan silinmiş malzeme yeniden açılmaz. TÜM TEST LİSTESİ ve PATOLOJİ bu dosyadan alınmaz (kopyada `#REF!`).

Migration `Version20261028090256`. Dosyalar (6.2'dekilerin üstüne):

| Klasör | Dosyalar |
|---|---|
| proje kökü | `mlyt-import.php`, `mlyt-import.bat` |
| `model\Entity\BIO\MLYT\` | `Material.php`, `MaterialPrice.php`, `Study.php` |
| `model\migration\` | `Version20261028090256.php` |
| `core\Mlyt\` | `PriceImport.php` (yeni), `ExcelImport.php`, `CostCalculator.php`, `PathologyExport.php` |
| `public\apps\biolab-maliyet\material\` | `material-1.0.0.html`, `js\material-1.0.0.js`, `php\material-1.0.0.php` |

### 6.4 Kit eşleştirme ekranı (30.10.2026)

Maliyet → Tanımlar → **Kit eşleştirme** (uzman ve yönetici). Biolab birimindeki Kit / Belirsiz / Reçete yöntemli,
reçetesinde kit dışı malzeme olmayan testlere "Kit" kategorisindeki malzemeleri ad benzerliğiyle önerir
(`core\Mlyt\KitMatcher.php`). Sekmeler: Yüksek güven (testin bütün ayırt edici kelimeleri kitte), Orta güven
(en az %60), Öneri yok, Kit bağlı. IgG / IgM / IgA uyuşmayan kit önerilmez; tek harf ve rakamlar ayırt edilir (TİP 1 / TİP 2,
VİTAMİN A / E). Uzman satırı işaretleyip (gerekirse kiti ve hasta başı miktarı değiştirip) "Seçilenleri bağla" der:
testin reçetesine hasta başı kit satırı yazılır (Malzemeler'deki "Teste bağla" ile aynı), yöntemi Belirsiz olan test Kit olur.
"Kit bağlı" sekmesinden bağlantı kaldırılabilir. Yeni tablo yok; tedarikçi kit listeleri aktarıldıkça öneriler kendiliğinden artar.

Migration `Version20261030090000` (ekran / menü / yetki kaydı). Dosyalar:

| Klasör | Dosyalar |
|---|---|
| `model\migration\` | `Version20261030090000.php` |
| `core\Mlyt\` | `KitMatcher.php` (yeni) |
| `public\apps\biolab-maliyet\kit_match\` (yeni klasör) | `kitMatch-1.0.0.html`, `js\kitMatch-1.0.0.js`, `php\kitMatch-1.0.0.php` |
| `public\lib\app\resources\tr\kit_match\` ve `en\kit_match\` (yeni) | `kitMatch-1.0.0.json` |

### 6.5 Son versiyon Excel'i: tedarikçi kuru, katalog no, kısa kod, hesap notu (09.11.2026)

Uzmanın `Kopya mlyt programı-son versiyon.xlsx` dosyası (MALZEME LİSTESİ; TL BİRİM MALİYET kolonu kalktı, J/K'da NUCLEUS
katalog no ve ürün adı, AÇIKLAMA'da hesap talimatları) için:

- **Tedarikçi kuru** (`mlyt_study_supplier_rate`): tedarikçiyle yıllık belirlenen kur (ör. NUCLEUS EUR 44,7). O tedarikçinin
  malzemeleri genel kur yerine bununla hesaplanır. Malzemeler → çalışma penceresi → "Tedarikçi kurları"; çubukta
  "NUCLEUS EUR 44,70" görünür.
- **Katalog no** (`mlyt_material.CATALOG_NO`): NUCLEUS kitleri katalog no ile tutulur, adı "ürün adı · katalog no" olur.
- **Kısa kod** (`mlyt_material.SHORT_CODE`): patoloji TK, L, LM, NSİ, SPS, BYK; Biolab mikroskopi N.LAM, R.LAM, LAMEL22,
  PASTÖR, GAİTA, STİ, PETRİ (migration'lar doldurur). Kısa kodlu her malzeme **Test tanımları**'nda her testte
  (Biolab ve Patoloji; hesaplamalı hariç) "Kısa kodlu malzemeler" altında hasta başı adetle seçilir (Excel'deki TK / L / LM
  kolonları, "N.LAM+LAMEL" notları); yeni test Excel'siz tanımlanır. Yeni kısa kod Malzemeler ekranından verilir.
  LM patoloji lameli (24×60), LAMEL22 mikroskopi lameli (22×22).
- **Bedelli malzeme** kategorisi (`BILLABLE`): doktorlara ürün bazlı fiyatlanan malzeme (ör. numune kabı). Bedeli
  (`mlyt_material.BILLABLE_PRICE`, TL / birim) bir kere girilir: Malzemeler → kategori "Bedelli malzeme" seçilince fiyat
  bölümü yerine "Bedel" alanı çıkar. Maliyet hesabı bu malzemeyi her çalışmada bu bedelle değerlendirir (kur ve çalışma
  fiyatı kullanılmaz); bedeli girilen bedelli malzeme "Fiyatı girilmiş" sayılır.
- **Hesap notu** (`mlyt_test.COST_NOTE`): uzmanın AÇIKLAMA'daki hesap talimatı (ör. "2 KİT TOPLANIP HASTAYA BÖLÜNÜR").
  Test tanımlarında düzenlenir, Test maliyetleri ayrıntısında sarı kutuda görünür.
- **Fiyat aktarımı:** bölen kuralı ambalaj (C) / adet (E) kolonlarından; "ambalajın tamamı fiyatı". Fiyatı değişmeyen
  satırlar için önceki Excel (TL BİRİM MALİYET kolonlu) **referans** verilir; bölen ondan alınır, sonuç önceki Excel'le aynı
  kalır (geliştirmede 211/211). Aynı adlı teste kit otomatik bağlanır (testte kit yoksa), "ekle" talimatları bağlanır.

Çalıştırma (`mlyt-import.bat`): **3** son versiyon dosyası → **7** önceki Excel (`Kopya mlyt programı.xlsx`) → **5** deneme
(çalışma no 7) → raporu kontrol → **6** uygula.

Migration'lar `Version20261109065144`, `Version20261109120000` (Biolab kısa kodları), `Version20261109130000`
(bedelli malzeme bedeli) ve `Version20261109140000` (collation düzeltmesi: sunucunun varsayılanı `Turkish_CI_AS`, eski
tablolar `Latin1_General_100_CI_AS_SC_UTF8`; yeni tablonun CURRENCY kolonu eskilerle aynı yapılır, yoksa Malzemeler
listesi "Cannot resolve the collation conflict" verir). Dosyalar:

| Klasör | Dosyalar |
|---|---|
| proje kökü | `mlyt-import.php`, `mlyt-import.bat` |
| `model\Entity\BIO\MLYT\` | `StudySupplierRate.php` (yeni), `StudyRate.php`, `Material.php`, `MaterialCategory.php`, `Test.php` |
| `model\migration\` | `Version20261109065144.php`, `Version20261109120000.php`, `Version20261109130000.php`, `Version20261109140000.php` |
| `core\Mlyt\` | `PriceImport.php`, `ExcelImport.php`, `CostCalculator.php`, `PathologyExport.php` |
| `public\apps\biolab-maliyet\material\` | `material-1.0.0.html`, `js\material-1.0.0.js`, `php\material-1.0.0.php` |
| `public\apps\biolab-maliyet\test\` | `test-1.0.0.html`, `js\test-1.0.0.js`, `php\test-1.0.0.php` |
| `public\apps\biolab-maliyet\test_cost\` | `testCost-1.0.0.html`, `js\testCost-1.0.0.js`, `php\testCost-1.0.0.php` |

## 7. Sonra yapılacaklar

- **HTTPS:** bilgi işlemden sertifika alınca site bağlamalarına `https` eklenir; http yönlendirilir veya kapatılır.
  Uygulamada değişiklik gerekmez (oturum çerezi HTTPS'te kendiliğinden "Secure" olur).
- **Güncelleme:** yeni sürümde yalnızca değişen dosyalar kopyalanır.
  - Veritabanı değişikliği varsa sunucuda `doctrine-migrations migrate` çalıştırılır.
  - `config.php` sunucudaki haliyle korunur; üzerine yazılmaz.
