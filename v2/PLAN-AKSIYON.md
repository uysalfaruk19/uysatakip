# PLAN — Aksiyon deseni uygulaması (branch: `tasarim-aksiyon`)

Ömer, 26 Ağu 2026: _"2. aksiyon odaklı akış şeklinde tümüne el at"_ → _"tasarımı tamamle sonra canlıya gönder"_.
Tasarım kaynağı: `D:\claude\vault\projeler\uysa-erp-v2\tasarim-onerileri\` + kurgu denetimi.

**Teslim biçimi:** hepsi bu dalda geliştirilir, TEK deploy ile canlıya çıkar (Ömer'in cümlesi bu sırayı
söylüyor). Her faz sonunda uygulanmış ekranın gerçek tarayıcı görüntüsü Ömer'e gösterilir — mockup
onayı tek başına yeterli değil (`omer-tasarim-zevki`: iki tur mockup onaylandı, uygulanmış hali reddedildi).

## AKSİYON DESENİ (her ekranda aynı beş kural)

1. **Karar rakamı** — en üstte tek büyük rakam.
2. **Akıllı durum + tek eylem** — tek satır durum + o satırı kapatan tek buton.
3. **Satır içi eylemli akış** — tek sütun liste, eylem satırın içinde.
4. **Sessizlik** — sorun yoksa uyarı yok; yalnız sapan satır işaretlenir.
5. **Tek birincil buton** — altta tek koyu buton; eksik varsa pasif.

## Değişmez kısıtlar

- **Eski URL'ler ÖLMEZ.** `siparisler.php`, `talepler.php`, `rapor.php` kalır (redirect) — push
  deep-link'leri ve iOS WebView bunları hedefliyor. Kırılan deep-link sahada sessiz arıza.
- **`public/m/` (müşteri PWA) kapsam dışı** — mockup'lar yönetici tarafı.
- Şema değişirse: `migrate_0NN.sql` idempotent + `_sqlite.sql` eşi + `schema_v2.sql` senkron.
- Her mutasyon ucunda CSRF + `audit` kaydı. SQL daima prepared.
- Paraşüt SALT-OKUMA. Bot API yazma sınırı değişmez. `/eski` salt-okunur kalır.
- Test: PHPUnit + gerçek tarayıcı (Playwright) + kıran girdiler (boş/0/negatif/Türkçe/çift tık).

## Faz sırası (bağımlılık sırasına göre)

### Faz 1 — Mükerrer kâr/zarar (en düşük risk, en görünür kazanç)

- `rapor.php` sayfa başlığı "Kâr / Zarar" → **"Üretim raporu"** (modüllerdeki adıyla aynı).
- `finans.php` içindeki "Kâr / Zarar detayı" bloğu → tek satır link (`kar-analizi.php`).
- `kar-analizi.php` aksiyon desenine geçer: tek net kâr rakamı + üç kişi başı maliyet kutusu +
  müşteri karnesi listesi (satır → drill).
- **Bitti-tanımı:** üç ekranda "kâr/zarar" tek anlama geliyor; aynı ay için üç ekran aynı net kârı
  gösteriyor (senkron testi); rapor.php URL'i çalışıyor; PHPUnit yeşil.

### Faz 2 — Bugün ekranı (desenin kalbi)

Yeni backend:

- **Öneri sayısı**: son 4 haftanın aynı günü `production.persons` ortalaması. <3 veri noktası varsa
  öneri GÖSTERİLMEZ. Asla otomatik yazılmaz — yalnız dokunuşla. Yazarken `entered_by='uysa'`,
  `unit_price_snap` o ayın `customer_price`'ından; **fiyat yoksa onay pasif**.
- **Anomali**: sapma eşiği `ayar` tablosunda parametrik (varsayılan %30). Yalnız sapan satırda uyarı.
- **Kesim geri sayımı**: `Helpers` kesim saatinden (müşteri bazlı ayar dahil).
- **Tahmini kâr**: ay-bugüne gerçekleşen kişi başı gıda maliyeti (gıda faturaları / ÜRETİM kişisi) —
  "tahmini" etiketi zorunlu, ay ortası şişkinlik uyarısı korunur.
- **Bitti-tanımı:** öneri onayı üretim kaydı yazıyor ve geri alınabiliyor; fiyatsız müşteride onay
  pasif; 3'ten az veri olan müşteride öneri yok; anomali eşiği ayardan değişiyor; çift tık mükerrer
  kayıt üretmiyor; gerçek tarayıcıda `element.value` ile doğrulandı.

### Faz 3 — Gelen (sipariş + talep tek kuyruk)

- Yeni `gelen.php`: `pendingOrders()` + `allRequests()` tek listede, tür şeritle ayrılır.
- `siparisler.php` ve `talepler.php` → `gelen.php`'ye redirect (URL'ler yaşar).
- **Bitti-tanımı:** onay/ret ve cevap uçları çalışıyor, audit yazıyor; eski URL'ler redirect ediyor;
  push deep-link'i hâlâ doğru ekrana düşüyor.

### Faz 4 — Navigasyon

- Mutfak + Sevkiyat → Bugün'ün içinde sekme. Ay kapanışı → Finans.
- (+) menüsü yalnız yeni kayıt: Gider ekle · Fatura kes · Müşteri ekle.
- `moduller.php` kalır ama küçülür (taşınanlar çıkar).
- **Bitti-tanımı:** her eski link bir yere çıkıyor (ölü link yok), tab bar 5 sekme kuralını koruyor.

### Faz 5 — Finans

- Belge zinciri: **kesildi** (`fatura.durum='kesildi'`) → **mail** (`mail_kuyruk.durum`) →
  **alacak** (`cari_entries` müşteri bakiyesi toplamı).
  ⚠️ Mockup'taki "3 tahsilat bekliyor" FATURA BAZINDA türetilemez (`fatura` tablosunda ödeme alanı
  yok) — zincirin 3. adımı ay bazlı toplam alacak olarak uygulanır.
- **Bitti-tanımı:** üç adımın da rakamı sorgudan geliyor, elle sayı yok; "Bekleyenleri gör" doğru
  filtreli listeye gidiyor.

### Faz 6 — Müşteriler + müşteri detayı

- Liste: karar rakamı + fiyatı girilmemişte satır içi "Fiyat gir".
- Detay: 737 satırlık form → üç kart (Aylık fiyat · Alt firmalar · Sabit kalemler) + "Düzenle".
- **Bitti-tanımı:** hiçbir alan kaybolmadı (alan sayımı yapılır), kaydetme tek POST, bağlı alanlar
  birlikte güncelleniyor.

### Faz 7 — Fatura Kes · Menü · Mutfak · Sevkiyat

### Faz 8 — Stok · Personel · Teklifler · Ay kapanışı

### Faz 9 — Öksüzler

- `haccp.php` menüye bağlanır (Operasyon grubu) — gıda güvenliği kaydı, silinmez.
- `yakinda.php` komple kaldırılır (route + link + dosya).

## Deploy (tek sefer, tüm fazlar bitince)

1. Rollback önce: VPS'te DB dump + çalışan imaj tag'i not edilir.
2. Lokal commit → GitHub push → VPS `git pull` → `docker build -f Dockerfile.v2` → `up -d`.
   `docker cp` YOK, inline SSH heredoc YOK (script + scp).
3. Pencere: 14:30 push cron'u ve 15:30 kesim saatine çakışmaz; sabah erken ya da akşam.
4. Duman testi: login → Bugün → bir üretim girişi yaz → **geri al** → geri alındığını doğrula.
5. Ömer'e tek fiziksel adım: iOS app'ten aç, bir sayı gir, kaydettiğini gör.

## Kaldığın yer çapası

Her faz bitince bu dosyanın altına tarih + faz + commit yazılır; oturum koparsa buradan devam edilir.

- 2026-08-26: Faz 0 tamam — dal açıldı, plan yazıldı, tahsilat varsayımı çürütüldü (fatura bazında
  ödeme alanı yok → zincir 3. adımı ay bazlı alacak), oturum kalıcılığı doğrulandı
  (`uysatakip_sessions` volume, rebuild kullanıcıyı atmaz).
- 2026-08-26: **Faz 1 tamam** (commit 11f4428) — rapor.php "Üretim raporu", finans linki
  kar-analizi'ye, karar rakamı filtrelerin üstüne. Test: 449 koştu, kırmızılar değişiklik
  ÖNCESİNDE de vardı.
- 2026-08-26: **Faz 2 tamam** — `Repo::onerilenKisi()` + Bugün ekranı öneri/onay/sapma/akıllı
  durum. 6 yeni test (OneriTest) yeşil, suite 455. Gerçek tarayıcıda doğrulandı: onay →
  input 70 · fiyatsız müşteride buton pasif ve zorla tıklansa da yazmıyor · çift tık bozmuyor ·
  yazılan kayıt geri alındı ve geri alındığı doğrulandı. Ekran mesajı hatası yakalandı ve
  düzeltildi ("geçmiş veri yetersiz" → "aylık fiyatı girilmemiş").
- 2026-08-26: **Faz 2 kapatıldı** — eksik kalan iki madde tamamlandı: (a) TAHMİNİ KÂR ciro
  altında ("tahmini · ay başından bugüne maliyetle"; maliyet hesaplanamıyorsa satır hiç
  basılmaz), elle hesapla birebir doğrulandı (56.945 − 220×384,80 = −27.710,33 = ekrandaki
  rakam); (b) eşik + tatil testleri (OneriEsikTest 4/4) — eşik ayardan değişiyor, alt sınır %5,
  ve RESMÎ TATİLDE sapma uyarısı hiç basılmıyor (yanlış pozitif kapatıldı: tatilde sayı meşru
  olarak düşer, fable-057 fatura-kişi kuralı da o gün uygulanmaz).
- 2026-08-26: **Faz 3 tamam** — `gelen.php`: bekleyen sipariş + açık talep TEK kuyrukta, en eski
  önce; tür yalnız sol şeritten (lacivert/turuncu), filtre çubuğu yok. Sipariş onay/ret satır
  içinde, siparisler.php ile AYNI akış (CSRF + `uysa_audit` + `addCustomerEvent`). Talep cevabı
  mevcut ekrana götürür — yeni cevap kutusu uydurulmadı.
  **KARAR DEĞİŞİKLİĞİ:** siparisler.php/talepler.php redirect EDİLMEDİ, olduğu gibi duruyor.
  Sebep: haftalık cetvel, talep filtreleri ve cevap kutusu orada; redirect işlev kaybı olurdu.
  Deep-link'ler (`/m/...`, `talepler.php?r=`) kırılmadı. Gelen = üst kuyruk, eskiler = detay.
  Tatbikat: BOMİ siparişi onaylandı → üretime 80 kişi yazıldı (₺21.200) → audit `siparis_onayla`
  + `customer_events` kaydı doğrulandı → GERİ ALINDI, yarın üretim 0'a döndü.
- 2026-08-26: **Faz 4 tamam** — gün şeridi (Giriş · Mutfak · Sevkiyat) üç ekranda da, tarih
  korunuyor; (+) menüsü sadeleşti (Gelen[rozet] · Fatura Kes · Borçlarım · Müşteri ekle · Diğer
  modüller); Mutfak/Sevkiyat gün şeridine, Ay kapanışı Finans'a taşındı. Ölü link denetimi:
  21 ekrandan toplanan **109 link, hepsi 200**.
- 2026-08-26: **Faz 5 tamam** — `Repo::belgeZinciri()` + Finans'ta tek şerit:
  kesildi (fatura.durum) → mail bekliyor (mail_kuyruk.durum) → **tahsil edilmedi (₺)**.
  Üçüncü adım fatura ADEDİ DEĞİL tutar: fatura bazında tahsilat bu şemadan türetilemiyor,
  müşteri cari bakiyelerinin pozitif toplamı yazılıyor (mockup'taki "3 tahsilat bekliyor"
  uydurma olurdu). Bekleyen adım amber çerçeveli; hiç belge yoksa şerit basılmaz.
  BelgeZinciriTest 5/5. Elle doğrulama: 100.000+105.000+110.000 = **₺315.000** = ekrandaki rakam.
- 2026-08-26: **Faz 6 tamam** — Müşteriler listesinde karar rakamı + "N müşterinin fiyatı
  girilmemiş" uyarısı; fiyatsız satırda "fiyat girilmemiş · Fiyat gir" (önceden sessizce
  "₺0,00 kişi başı" yazıyordu). Detayda karar rakamı (o ayki tutar/kişi) + Paraşüt carisi
  bağlı değilse tek uyarı. ALAN KAYBI YOK: 13 form alanı ve tüm bloklar yerinde (ölçüldü).
  Not: taşıma tarafında "ciro" değil KÂR tutulduğu için ikisi toplanmadı, etiket bunu yazıyor.
- 2026-08-26: **Faz 7-8 tamam** — ortak `partials/karar.php` bileşeni (karar rakamı + akıllı
  durum + sessizlik kuralı tek yerde) ve şu ekranlara uygulandı: Mutfak, Sevkiyat, Stok,
  Teklifler, Fatura Kes, Personel, Menü, Ay kapanışı. Uydurma rakam kapatıldı: teklifte
  `fiyat` yerine `birim_fiyat`, "aylık potansiyel" yerine "günlük" (iş günü sayısı varsayım
  olurdu); fatura adayında `tutar` alanı yok → `toplam × birim` türetildi; menüde durum
  değerleri `draft/published`.
- 2026-08-26: **Faz 9 tamam** — `haccp.php` Operasyon grubuna bağlandı (gıda güvenliği kaydı,
  silinmedi); `yakinda.php` komple kaldırıldı (dosya + referans yok, 404 doğrulandı).
  Ölü link denetimi: 28 ekran, **118 link, kırık 0**.
- **SIRADAKİ:** deploy (rollback → push → VPS pull → build → duman testi).

