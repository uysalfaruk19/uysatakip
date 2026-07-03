# UYSA Kokpit (ERP v2) — Faz 1

Mobil-öncelikli, ilişkisel catering operasyon paneli. v1'in (14 modül, 21k satır tek HTML,
key-value JSON) yerine sıfırdan yazıldı. İş emri: `is-emirleri/opus-004-uysa-erp-v2.md`,
mimari: `vault/projeler/uysa-erp-v2/mimari.md`.

## Kapsam (F1)
- **Bugün** (M1): günlük üretim girişi — müşteri × kişi → tutar otomatik (birim fiyat snapshot),
  upsert `UNIQUE(customer,prod_date,meal)`, dünü kopyala, gün toplamı, eksik müşteri işareti.
- **Finans** (M2): gelir/gider akışı (ay filtreli, tür segment) + hızlı ekle + fatura foto yükleme.
- **Cari** (M3): müşteri kartı + bakiye + aylık ekstre + tahsilat.
- **Menü/Rapor**: F2 placeholder (Rapor'da temel ay×müşteri tablosu var).
- **API** (`/api`): `POST /api/uretim` (fuzzy ad eşleşme, bot), `GET /api/ozet`, `GET /api/rapor` — X-UYSA-Token.
- **Migrasyon**: `tools/migrate_v1.php` — v1 `uysa_storage` → ilişkisel tablolar, mojibake onarımı,
  reconciliation raporu. `lafetta_*`/`hikari_*` TAŞINMAZ (DTakip). v1 tablolarına dokunulmaz.

M6 müşteri tabloları (customer_users, orders, requests, request_messages, announcements) şemada
DAHİL — F1'de UI yok, F2'de açılır (sonradan migration istemesin).

## Kurulum
```bash
cp .env.example .env          # DB + API_TOKEN + ADMIN_PASS/STAFF_PASS doldur
php tools/setup_db.php        # şema (sql/schema_v2.sql) + OFU/Azim kullanıcı seed
php tools/migrate_v1.php --dry-run   # v1 → v2 önizleme + reconciliation
php tools/migrate_v1.php             # gerçek migrasyon
php -S 127.0.0.1:8099 -t public router.php   # geliştirme sunucusu
```
Üretim: web kökü `public/`, `/api/*` rewrite (traefik/Apache). MySQL/MariaDB utf8mb4.

## Test
```bash
phpunit --configuration phpunit.xml   # PHPUnit 9+ (PHP 8.0 uyumlu); SQLite in-memory
```

## Yapı
- `sql/schema_v2.sql` (MySQL, kanonik) · `sql/schema_sqlite.sql` (test)
- `src/` — Env, Db (MySQL/SQLite fabrika), Auth (bcrypt+oturum), RateLimiter, Repo, Helpers, bootstrap
- `public/` — sayfalar + `api/` + `assets/` (kendine yeten dark CSS + JS) + `partials/`
- `tools/` — setup_db, migrate_v1 · `bot/uretim-gir.md` — OFUclaw skill · `tests/` — PHPUnit

Güvenlik: tüm SQL prepared statement; sır `.env`'de (koda gömülü sıfır secret); CSRF (form),
X-UYSA-Token + rate-limit (API/login); upload tip/MIME whitelist + `uploads/.htaccess` exec off.
