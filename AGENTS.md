# AGENTS.md — UYSA Kokpit (uysatakip)

## Proje
Mobil-öncelikli catering operasyon uygulaması. Canlı: https://uysatakip.uysa019.cloud
(Docker + Traefik). **Aktif kod `v2/` altındadır** — repo kökündeki `public/`, `sql/`, `tests/`
v1 arşividir (tek dosyalık SPA, canlıda `/eski/` altında SALT-OKUNUR yaşar). iOS uygulaması
(Capacitor) da bu backend'i kullanır; push otomasyonu (APNs) canlıdır. DB: `uysa_v2`
(MySQL/MariaDB utf8mb4); v1 kendi DB'sini (`uysa_db`) okur, ona dokunulmaz.

## Dizin haritası (v2/)
- `router.php` — dev VE üretim tek router: pretty `/api/*`, `/eski/*` v1 arşiv servisi.
- `src/` — `bootstrap.php` (autoload `Uysa\` + .env + PDO), `Repo.php` (iş mantığının çoğu),
  `Db` (MySQL/SQLite fabrika), `Auth`/`CustomerAuth`/`Remember`, `Push.php` + `Apns.php`,
  `Parasut.php` (SALT-OKUMA), `Helpers.php` (kesim saati vb.), `MenuPdf`/`TeklifPdf`
  (`src/lib/` FPDF bundle — composer YOK), `XlsxMenu`, `RateLimiter`.
- `public/` — admin sayfaları (bugun, finans, cari, menu, talepler, kar-analizi, bildirim...) +
  `public/m/` müşteri PWA sayfaları + `public/api/` bot/iç API + `assets/` (dark CSS/JS, push.js).
- `sql/` — `schema_v2.sql` (MySQL, kanonik) + `schema_sqlite.sql` (test) + `migrate_0NN.sql`
  artımlı migration'lar (idempotent; SQLite eşleri `_sqlite.sql`).
- `tools/` — `setup_db.php`, `migrate_v1.php`, `push_reminder.php` (cron 14:30),
  `parasut_sync.php` (SADECE Ömer'in PC'sinde koşar).
- `tests/` — PHPUnit (SQLite in-memory) + `push-js-smoke.mjs` · `bot/` — bot skill taslakları.

## Çalıştırma / test / deploy
```bash
cd v2
cp .env.example .env                          # DB + API_TOKEN + ADMIN_PASS doldur
php tools/setup_db.php                        # şema + kullanıcı seed
php -S 127.0.0.1:8099 -t public router.php    # dev sunucu
phpunit --configuration phpunit.xml           # tüm testler (SQLite, ağa çıkmaz)
node tests/push-js-smoke.mjs                  # push.js smoke
```
Üretim imajı: `docker build -f Dockerfile.v2 .` (repo kökünden; v1'i `/eski`ye kopyalar).
VPS'te `docker-compose.v2.yml` (`.env.v2`, 127.0.0.1:8093→8080, external network
`uysatakip_uysanet` → Traefik). CI: `.github/workflows/ci.yml` (lint + PHPUnit).

## Stil kuralları
- Türkçe UI metni, İngilizce değişken/fonksiyon adı. Yorum minimum — sadece NEDEN'i yaz.
- Erken return > iç içe if. Tekrarı fonksiyona/`Repo`'ya al.
- SQL daima prepared statement — string'e değer gömme.
- Sır/parola koda GÖMÜLMEZ → `.env` (`Uysa\Env`); `.env` git'e girmez.
- Form'larda CSRF, API'de `X-UYSA-Token` + rate-limit; upload MIME whitelist.
- Değişiklikten önce dosyayı oku, çevre stiline uy; iş bitince testleri koştur.

## Kritik tuzaklar (koddan doğrulanmış)
- **Timezone:** `src/bootstrap.php` `date_default_timezone_set('Europe/Istanbul')` — container
  UTC; bu satır olmadan kesim 18:30'a kayar, gece 00-03 gün atlar. Tarih/saat işinde buna güven.
- **Sipariş kesim saati:** `Helpers.php` — müşteri, teslim gününden BİR GÜN ÖNCE 15:30'a kadar
  değiştirebilir (müşteri bazlı ayar var). Test için `$now` enjekte edilebilir.
- **Push tek kapı:** her push `src/Push.php` üzerinden — `push_log`'a yazar, ölü token siler,
  menü push'unda mükerrer koruması (`ref='menu:ID'`), sessiz saat 21:00–07:00 (olay push'ları
  bastırılır, elle `bildirim.php` MUAF). APNs yapılandırılmamışsa sessiz no-op. Testte Apns stub —
  gerçek APNs'e istek atma.
- **/eski salt-okunur:** `router.php` v1 `uysa_api.php` yazma action'larını (set/delete/backup...)
  403'ler. Bu korumayı gevşetme.
- **Paraşüt = canlı muhasebe, SALT-OKUMA:** `src/Parasut.php` yalnız GET; kredensiyaller VPS'e
  KONMAZ, senkron yerelde `tools/parasut_sync.php`. Paraşüt'e yazan kod ekleme.
- **Bot API yazma sınırı:** yalnız üretim + gider; silme/yıkıcı uç YOK. Fuzzy eşleşme düşük
  güvende 422 döner, yazmaz — bu davranışı koru.
- **Şema değişikliği:** yeni `sql/migrate_0NN.sql` (idempotent) + SQLite eşi + test şemasını
  güncelle; `schema_v2.sql`'i de senkron tut.
- Müşteri uçlarında IDOR-scope zorunlu (`m/dosya.php`, `m/menu.php?pdf=` deseni).
- FPDF cp1252: ş/ğ/ı yok → transliterasyon var; PDF metinlerinde buna dikkat.
