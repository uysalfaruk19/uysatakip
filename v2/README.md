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
- **Paraşüt cari (opus-012, SALT-OKUMA)**: Paraşüt (CANLI muhasebe) müşteri bakiyeleri → Kokpit.
  🔒 Kredensiyaller VPS'e KONMAZ — senkron YEREL çalışır: `php tools/parasut_sync.php` (Ömer'in
  PC'sinde `secrets/parasut.txt`'ten okur, Paraşüt'ten cari çeker = GET, sonuç bakiyeleri
  `POST /api/parasut_cari`'ye X-UYSA-Token ile gönderir). Sunucu Paraşüt'e HİÇ çağrı yapmaz.
  Paraşüt'e yazma YOK (`src/Parasut.php` yalnız `get()`/`contacts()`). Eşleşme: parasut_id >
  vergi no > ad-normalize; eşleşmeyen RAPORLANIR (oto müşteri oluşturma yok). Gösterim: cari.php +
  müşteri kartı "Paraşüt bakiyesi (muhasebe)" + admin `parasut.php` (durum + eşleşmeyen raporu,
  buton çekmez). Şema: `sql/migrate_012.sql` (canlı DB, idempotent).
- **Migrasyon**: `tools/migrate_v1.php` — v1 `uysa_storage` → ilişkisel tablolar, mojibake onarımı,
  reconciliation raporu. `lafetta_*`/`hikari_*` TAŞINMAZ (DTakip). v1 tablolarına dokunulmaz.

M6 müşteri tabloları (customer_users, orders, requests, request_messages, announcements) şemada
DAHİL — F1'de UI yok, F2'de açılır (sonradan migration istemesin).

**Müşteri app iyileştirmeleri (opus-019):**

- **Sipariş default sayı**: müşteri dokunmazsa önceki günkü sayı gelir (`Repo::lastPersonsFor`).
- **Cari "Ekstre Talep Et"**: Paraşüt öncesi bakiye KAPALI; ekstre talebi `requests`'e yazılır.
  `.env` `CARI_LIVE=1` → gerçek bakiye/ekstre açılır.
- **Menü PDF**: `src/lib/fpdf.php` (FPDF 1.86 bundle, composer YOK) + `src/MenuPdf.php` → grid PDF.
  Admin `menu.php?pdf=<id>` · müşteri `m/menu.php?pdf=<id>` (IDOR-scope). Türkçe ş/ğ/ı cp1252'de yok →
  transliterasyon (ç/ö/ü korunur). Müşteri menü geçmişi EN FAZLA 1 ay geri.
- **Talep+foto+takip**: `requests.type` → talep/sikayet/mesaj/**menu/oneri**; foto eki
  `request_messages.file_id` (finans upload deseni). Müşteri `m/talep.php` (durum/mesaj/foto) + admin
  `public/talepler.php` (tip/durum/müşteri filtre, cevap+durum). Foto servisi IDOR-scope:
  `public/dosya.php` (admin) · `public/m/dosya.php` (müşteri, sadece kendi eki). Şema: `sql/migrate_019.sql`.

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
- `src/` — Env, Db (MySQL/SQLite fabrika), Auth (bcrypt+oturum), RateLimiter, Repo, Helpers, bootstrap,
  XlsxMenu, MenuPdf · `src/lib/` — FPDF 1.86 bundle (fpdf.php + font/, composer YOK)
- `public/` — sayfalar + `api/` + `assets/` (kendine yeten dark CSS + JS) + `partials/`
- `tools/` — setup_db, migrate_v1 · `bot/uretim-gir.md` — OFUclaw skill · `tests/` — PHPUnit

Güvenlik: tüm SQL prepared statement; sır `.env`'de (koda gömülü sıfır secret); CSRF (form),
X-UYSA-Token + rate-limit (API/login); upload tip/MIME whitelist + `uploads/.htaccess` exec off.
