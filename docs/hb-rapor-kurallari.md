# HbRapor — Rapor kuralları (laboratuvar cevapları)

Kaynak: uzmanın 20.10.2026'da verdiği cevaplar. Uzman onay ekranı (4. adım) ve rapor (5. adım) bu kurallara göre yapılır.
Yazılım hiçbir şüpheli sonucu kendiliğinden kesinleştirmez: hesaplar, uyarır, taslak hazırlar; karar uzmanındır.

Bu belgede "anormal pik" şu pikler demektir: **Unknown, S-Window, D-Window**.

## 1. HbF değeri
- Cihaz `< 0.8` verirse raporda **0.8** yazılır ve HbA hesabında 0.8 kullanılır.
- Cihazın verdiği ham değer (`< 0.8`) veritabanında değiştirilmeden saklanmaya devam eder.

## 2. HbA hesabı
- **Normal patern** (hiçbir anormal pik ≥ %5 değil): `HbA = 100 − (HbA2 + HbF)`.
  - %5'in altındaki anormal pikler hesaptan **çıkarılmaz**.
  - %5 sınırı her pik için ayrı ayrı değerlendirilir.
- **Herhangi bir anormal pik ≥ %5 ise:**
  - HbA yine aynı formülle hesaplanır, ama **taslak** olarak gösterilir ve kendiliğinden onaylanmaz.
  - Onay ekranında cihazın A0 değeri de yanında gösterilir.
  - Ekranda şu uyarı çıkar: "Kromatogramdaki A0 değerini kontrol ediniz."
  - Raporlanacak HbA değerini uzman belirler.
- ≥ %5 pikler 100'den otomatik olarak **düşülmez**. Sebep: Unknown ve S-Window kesin tanımlanmış bir varyant değildir.

## 3. HbS / HbD satırı
- Şüpheli pik yoksa satıra **SAPTANMADI** yazılır.
- S-Window, D-Window ya da bunlarla ilişkili olabilecek şüpheli bir Unknown pik varsa (< %3 olsa bile): satıra **DOĞRULAMA GEREKLİ** yazılır ve sonuç uzman kontrolüne düşer.
- S-Window pikine hiçbir zaman doğrudan "HbS" yazılmaz.

## 4. V blokları (anormal fraksiyon yorumu)

| Anormal pik % | Yazılım ne yapar |
|---|---|
| < %3 | V bloğu oluşturulmaz |
| %3 ≤ pik < %5 | **V1** yorum taslağı hazırlanır. Uzman kromatogramı kontrol eder, uygun bulursa rapora eklenir |
| ≥ %5 | Diğer kriterler de değerlendirilerek uygun V bloğu taslağı hazırlanır ve uzman onayına sunulur |

- Tüm anormal pikler gösterilir; karar yalnızca en büyük pike göre verilmez.
- Birden fazla pik ≥ %3 ise ayrıca uzman kontrol uyarısı çıkar.

## 5. Varyant ile HbA2 yüksekliği birlikteyse
1. Önce hastanın yaşına göre **A, B ya da C** bloğu seçilir.
2. Anormal pik varsa uygun V bloğu da eklenir. İki bloktaki tekrarlayan ya da çelişen cümleler birleştirilir.
3. Varyant piki varken "Ek anormal pik saptanmamıştır." cümlesi rapora **gelmez**.
4. Onay ekranında şu uyarı çıkar: "HbA2 ve kromatogramı kontrol ediniz." Sebep: anormal pikler HbA2 ölçümünü etkileyebilir.

## 6. Çok yüksek HbA2
- Yaşa uygun **C4** bloğu korunur.
- HbA2 > %10 ise şu uyarı çıkar: "HbA2 beklenenden yüksek; kromatogramı ve olası varyant interferansını kontrol ediniz."

## 7. HbA2 sınırları

| Aralık |
|---|
| HbA2 < %3.5 |
| %3.5 ≤ HbA2 < %4.0 |
| %4.0 ≤ HbA2 < %5.5 |
| HbA2 ≥ %5.5 |

## 8. Tekrar çalışma
- Numune birden fazla çalışıldıysa hangi çalışmanın (kromatogramın) raporlanacağını **uzman seçer**.
- Yazılım otomatik seçim yapmaz. Onay ekranında tüm çalışmalar yan yana gösterilir.

## 9. Yorum blokları

Kaynak: `Documents\Elektroforez\hplcalgoson01.06.docx`. Blok metinleri bu belgeden birebir alınır. Aşağıda yalnızca seçim kriterleri var.

**Yaş grubu (kabul tarihindeki yaşa göre):**

| Grup | Yaş |
|---|---|
| A | < 1 yaş (0–12 ay) |
| B | 1 – < 6 yaş |
| C | ≥ 6 yaş |

**HbF yaşa göre referans üst sınırları:**

| Yaş | Aralık / üst sınır |
|---|---|
| 0–30 gün | %22.8–92 |
| 1–2 ay | %7.6–89.8 |
| 2–5 ay | %1.6–42.2 |
| 5–8 ay | %0–16.7 |
| 8–12 ay | %10.5 |
| 12–17 ay | %7.9 |
| 17–24 ay | %6.3 |
| 24 ay ve üstü | %3.0 |

**A blokları (< 1 yaş).** Önce yaşa göre HbF'ye bakılır.

| Blok | Kriter |
|---|---|
| A1 | HbF fizyolojik aralıkta |
| A2 | HbF üst sınıra yakın |
| A3 | HbF, üst sınırın %20 üstünde |
| A4 (major alarm) | 2 aydan sonra HbF > 90 ve HbA < 10 |

**B blokları (1 – < 6 yaş):**

| Blok | Kriter |
|---|---|
| B1 | HbA2 < 3.5 |
| B2 | 3.5 ≤ HbA2 < 4.0 |
| B3 | 4.0 ≤ HbA2 < 5.5 |
| B4 | HbA2 ≥ 5.5 |
| B5 | HbF yaş üst sınırının üzerinde ve HbA2 < 3.5 (izole HbF) |
| B6 | HbA2 ≥ 5.5 ve HbF yaş üst sınırının üzerinde |

**C blokları (≥ 6 yaş):**

| Blok | Kriter |
|---|---|
| C1 | HbA2 < 3.5 ve HbF < 2 |
| C2 | 3.5 ≤ HbA2 < 4.0 |
| C3 | 4.0 ≤ HbA2 < 5.5. HbF > 5 ise C3 notu eklenir |
| C4 | HbA2 ≥ 5.5. HbF > 5 ise C4 notu eklenir |
| C5 | 2 ≤ HbF ≤ 5 ve HbA2 < 3.5 |
| C6 | 5.1 ≤ HbF ≤ 20 ve HbA2 < 3.5 |
| C7 | 20.1 ≤ HbF ≤ 60, HbA ≥ 30 ve HbA2 ≤ 3.5 |
| C8 | 60.1 ≤ HbF ≤ 85, 10 ≤ HbA ≤ 30 ve HbA2 < 3.5 |
| C9 | HbF ≥ 85 ve HbA ≤ 10 |

**V blokları** (anormal fraksiyon = en yüksek anormal pik):

| Blok | Kriter |
|---|---|
| V1 | %3 ≤ pik < %5 ve HbA ≥ 70 |
| V2 | %5 ≤ pik < %10 ve HbA ≥ 70 |
| V3 | %10 ≤ pik ≤ %50 ve HbA ≥ 70 |
| V4 | %10 ≤ pik ≤ %50 ve 10 ≤ HbA < 70 |
| V5 | %10 ≤ pik ≤ %50, HbA ≥ 70 ve HbF ≥ 5 |
| V6 | %10 ≤ pik ≤ %50, 10 ≤ HbA < 70 ve HbF ≥ 5 |
| V7 | pik > %50 ve HbA ≤ 10 |

**Blokların birleştirilmesi (kural 5):**
- Rapor yorumu = yaş bloğu + (varsa) V bloğu.
- Varyant varken yaş bloğundaki "Yapısal hemoglobin varyantı lehine ek bir pik saptanmamıştır." cümlesi çıkarılır.
- Her blokta tekrarlanan kapanış cümlesi ("Belirtilen değerler klinik değerlendirmede yol gösterici karar düzeyleridir…") en sonda bir kez yazılır.
- Yazılımın hazırladığı metin taslaktır; uzman düzenleyebilir.

**Raporun değişmezliği** (Ekran Akışı belgesi):
- Onaylanmış rapor değiştirilemez.
- Düzeltme, yeni bir revizyon olarak kaydedilir.

## 10. Yazılımda nasıl uygulandı

Kurallar `core/Hb/HbReportRules.php` dosyasında, blok metinleri `hb_comment_block` tablosunda.
Uzman onaylamadan önce kurallar sunucuda yeniden çalıştırılır.

Açık sorular cevaplanana kadar şu yorumlar kullanılıyor:

- **Anormal pik:** Normal pik listesinde olmayan her pik anormal sayılır. Normal pikler: A1a, A1b, F, LA1c/CHb-1, A1c, P3, A0, A2.
  - Uzmanın saydığı pikler: Unknown, S-Window, D-Window.
  - Bunların dışında beklenmeyen bir pik adı gelirse ayrıca uyarı çıkar.
- **HbF yoksa:** Cihaz HbF vermediyse (F piki yoksa) "< 0.8" gibi değerlendirilir ve raporda 0.8 yazılır.
- **HbS / HbD:** Herhangi bir anormal pik varsa, %3'ün altında olsa bile, otomatik "SAPTANMADI" yazılmaz; uzman seçer (açık soru 8).
- **A grubu:**
  - HbF yaş aralığındaysa A1 önerilir. Ekranda "A2/A3 sınırları tanımlı değil" bilgisi gösterilir.
  - HbF aralık dışındaysa blok önerilmez (açık soru 1–2).
  - 8–12 ay için %10.5 üst sınır kabul edildi.
- **B grubu:** "HbF yaşa göre yüksek" = HbF ≥ üst sınır. Belgedeki "≥ %x" yazımına göre.
- **Kriterlerin kapsamadığı birleşimler** (açık soru 4): blok önerilmez. Ekran aday blokları gösterir, uzman seçer.
- **V bloğu ve HbA:**
  - V bloğu, en yüksek anormal pike göre seçilir.
  - Pik ≥ %5 ise HbA'yı uzman girer; V bloğu bu değere göre önerilir.
- **Varyant:** Kural 5'teki "varyant", %3 ve üzerindeki anormal piktir.
- **Revizyon:** Onaylı rapor değişmez. Düzeltme gerekçeyle yeni revizyon olarak kaydedilir; eski revizyon saklanır.

## 11. Rapor PDF'inde yorum ve referanslar (20.10.2026 kararı)

**Yorum iki ayrı alandır:**
- **Algoritma yorumu:** seçilen blokların metni.
  - Uzman değiştirebilir; değiştirirse kayıtta COMMENT_EDITED_FL = Y olur.
  - Ekranda ve onaylı raporda "Blok metni düzenlendi" rozeti görünür.
  - Boşluk ve satır sonu farkları değişiklik sayılmaz.
- **Uzman notu:** isteğe bağlı, serbest metin.
  - Raporda yorumun altında ayrı kutuda, "Uzman notu · onaylayan" başlığıyla görünür.

**Referans aralıkları:** Tenay raporundaki değerler kullanılır; değiştirmek için `config.php` → `hb_report.refs`.

| Test | Aralık | Açıklama |
|---|---|---|
| HbA | 95.8 – 98.0 | < 1 yaşta yazılmaz |
| HbA2 | 2.0 – 3.5 | 3.5 ve üstü ▲ (kurallarla aynı sınır) |
| HbF | 0.0 – 2.0 | ≥ 6 yaşta; daha küçüklerde yaşa göre tablo |

- Aralık dışı değerler ▲ / ▼ ile işaretlenir.
- Numune türü: Tam Kan (EDTA).

## 12. Rapor PDF'i (21.10.2026 kararları)

- **Tek sayfa, bizim tasarım.** Cihazın orijinal çıktısı rapora eklenmez.
  - Cihaz PDF'i değiştirilmeden veritabanında arşivlenir; onay ekranındaki "Cihaz orijinali (arşiv)" bağlantısından açılır.
  - Gerekirse yeniden eklenebilir: `config.php` → `hb_report.append_device_page` = `true`.
- **Dosya adı:** Sample ID (barkod) - onay tarihi.
  - Rapor: `11714240-21.10.2026.pdf`
  - Düzeltme: `11714240-21.10.2026-R2.pdf`
  - Önizleme: `11714240-21.10.2026-TASLAK.pdf`
- **Kromatogram:** cihaz eğrisi aynen, yatay genişletilmiş.
  - Eksen değerleri büyük yazılır.
  - Pik etiketleri tepelerin üstünde, cihazın kuralıyla: F / A1c / A2 adıyla, diğerleri süreyle; A0 etiketlenmez.
- **Pik tablosu:** cihaz tablosuyla aynı sütunlar (Süre, Yükseklik, Alan, Alan %) ve toplam alan.

## 13. Tenay entegrasyonu (21.10.2026)

- `TenayService.asmx` (`HastaListesiGetirDetayli`, `HastaSonucSorgulaOrnekNo`) örnek numarasıyla hasta bilgisi verebiliyor.
- Ancak kurum kodu olmadığı için kullanılamıyor.
- Hasta bilgileri numune kabulde elle girilmeye devam eder.

## Açık sorular (uzmana sorulacak)
1. **A2 "üst sınıra yakın":** Sayısal sınırı nedir (ör. üst sınırın %90'ı)?
   - A3'teki "%20 üstü", üst sınır × 1.2 mi, yoksa üst sınır + 20 puan mı?
2. **0–8 ay:** HbF'nin aralık altında kalması (ör. 2–5 ayda < %1.6) ne anlama geliyor, hangi blok seçilir?
   - 8–12 ay için verilen "≥ %10.5" alt sınır mı, üst sınır mı? B5'te aynı değer üst sınır olarak geçiyor.
3. **A grubunda HbA2:** HbA2 yüksekse (ör. 11 aylık, HbA2 4.5) A bloğu mu, B/C kriteri mi uygulanır?
4. **Kriterlerin kapsamadığı birleşimler:** Aşağıdaki durumlarda hangi blok seçilir? (Şimdilik yazılım blok önermez, "Uzman seçmeli" der.)
   - B grubunda HbA2 3.5–5.5 ve HbF yüksek.
   - C grubunda HbA2 3.5–4.0 ve HbF > 2.
   - C7 aralığında HbF ama HbA < 30.
   - V2 ama HbA < 70.
   - Pik > %50 ama HbA > 10.
5. **C7'de HbA2 ≤ 3.5:** Bu "< 3.5" mi olmalı? Diğer bloklar ve Soru 7 cevabı, 3.5'i "≥ 3.5" aralığına koyuyor.
6. **Tam sınırlar:** Pik tam %10 ya da tam %50 olduğunda hangi V bloğu seçilir? HbA tam 70 olduğunda?
7. **"18 yaş üstü hastalarda…" cümlesi:** 6–18 yaş arası hastanın raporundan çıkarılsın mı?
8. **"S/D ile ilişkili olabilecek Unknown":** Bunu tutma süresi (RT) aralığıyla mı tanımlayacağız? Öyleyse S ve D pencerelerinin RT aralıkları nedir?
   - Cihaz bu pikleri raporda "S-Window" / "D-Window" adıyla mı veriyor? Şu ana kadar gelen raporlarda yalnızca A1a, A1b, F, LA1c/CHb-1, A1c, P3, A0, A2, Unknown görüldü.
9. **Soru 3 ve Soru 4 birlikte:** %3'ün altındaki S/D penceresi pikinde V bloğu çıkmayacak, ama HbS/HbD satırı "DOĞRULAMA GEREKLİ" olacak. Bu yorum doğru mu?
10. ~~**Rapor sayfa 2:** A seçeneği mi, B seçeneği mi?~~ **Cevaplandı (21.10.2026):** B — yeniden tasarlanmış tek sayfa; cihaz sayfası rapora eklenmez (bkz. bölüm 12).
