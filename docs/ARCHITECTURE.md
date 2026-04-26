# UYSA ERP — Mimari Dokumani

## 1. Genel Yapi

```
uysatakip/
├── public/                     # Web root (Railway deploy)
│   ├── index.html              # SPA frontend (~26K satir, vanilla JS)
│   ├── uysa_api.php            # Ana API router + core islemler
│   ├── health.php              # Saglik kontrolu endpoint'i
│   └── src/
│       └── modules/            # Domain-specific backend moduller
│           ├── FinanceModule.php
│           ├── InventoryModule.php
│           ├── HRModule.php
│           ├── PortalModule.php
│           ├── CateringModule.php   # v5 — COZBIM entegrasyonu
│           └── TelegramBot.php
├── src/
│   └── JwtManager.php          # JWT token yonetimi
├── sql/
│   └── schema_v5.sql           # Tam veritabani semasi (52 tablo)
├── tests/
│   ├── UnitTest.php            # JwtManager, RateLimiter, core unit testleri
│   ├── ModuleTest.php          # Modul syntax, handler, schema dogrulama
│   ├── CateringModuleTest.php  # COZBIM CRUD + is mantigi testleri
│   └── IntegrationTest.php     # API + DB entegrasyon testleri
├── docs/
│   ├── ARCHITECTURE.md         # Bu dosya
│   ├── MIGRATION_v5.md         # v5 gecis rehberi
│   └── COZBIM_REFERANS.md      # COZBIM sektor analizi
├── .github/workflows/ci.yml   # CI/CD pipeline
├── Dockerfile                  # PHP 8.2 CLI server
├── railway.toml                # Railway deploy konfig
└── composer.json               # PHP bagimliliklari
```

## 2. Backend Mimarisi

### 2.1 API Router Deseni

`uysa_api.php` — merkezi router:
- `?action=xxx` parametresi ile action dispatch
- Core action'lar (ping, set, get, backup, vb.) switch/case ile
- Domain modulleri prefix-based router ile (`$moduleMap`):

```
fin.*    → FinanceModule.php   → handleFinanceAction()
inv.*    → InventoryModule.php → handleInventoryAction()
hr.*     → HRModule.php        → handleHRAction()
portal.* → PortalModule.php    → handlePortalAction()
cat.*    → CateringModule.php  → handleCateringAction()
```

### 2.2 Module Handler Imzasi

```php
function handleXAction(
    string $action,    // Tam action adi (ornek: "cat.dishSave")
    PDO $pdo,          // MySQL baglantisi
    array $body,       // POST body (JSON decode edilmis)
    ?array $authedUser,// JWT payload veya null
    string $clientIp   // Istemci IP adresi
): void
```

### 2.3 Yardimci Fonksiyonlar (uysa_api.php)

| Fonksiyon | Amac |
|-----------|------|
| `jsonResponse(array, int)` | JSON cikti + exit |
| `sanitizeInput(mixed, int)` | Trim + max uzunluk |
| `auditLog(PDO, ...)` | uysa_audit tablosuna log |

## 3. Frontend Mimarisi

### 3.1 SPA Yapisi

- Tek dosya: `public/index.html` (~1.4MB, ~26K satir)
- Vanilla JavaScript (framework yok)
- IIFE pattern ile modul kapsulleme
- `window._rc` namespace ile moduller arasi iletisim
- localStorage-based veri saklama + sunucu sync

### 3.2 localStorage Anahtar Yapisi

| Key | Format | Kullanan Modul |
|-----|--------|----------------|
| `uysa_customers_v1` | `{customers: [...]}` | Musteri |
| `uysa_recipes_v2` | `[...]` | Recete |
| `uysa_butceler` | `[...]` | Butce |
| `uysa_borclar` | `[...]` | Borclar |
| `uysa_uretim_gider` | `[...]` | Uretim |
| `uysa_gunluk_uretim` | `[...]` | Gunluk uretim |

## 4. Veritabani

### 4.1 Schema Ozeti (v5 — 52 tablo)

**Core:** uysa_storage, uysa_audit, uysa_webhooks, uysa_api_tokens
**Finans:** uysa_accounts, uysa_journal_entries/lines, uysa_invoices/lines, uysa_payments, uysa_bank_accounts/transactions
**Stok:** uysa_warehouses, uysa_products, uysa_stock_movements, uysa_suppliers, uysa_purchase_orders, uysa_lots
**IK:** uysa_employees, uysa_leave_types/requests, uysa_attendance, uysa_shifts, uysa_payroll
**Musteri:** uysa_customers, uysa_customer_orders
**Portal:** uysa_2fa, uysa_ai_chats, uysa_telegram_users
**COZBIM (v5):** uysa_dishes, uysa_recipe_lines, uysa_menu_plans, uysa_menu_plan_items, uysa_parties, uysa_deliveries, uysa_haccp_logs, uysa_dish_pairings

### 4.2 FK Iliski Haritasi (COZBIM)

```
uysa_dishes ──┬── uysa_recipe_lines (CASCADE)
              ├── uysa_menu_plan_items (RESTRICT)
              └── uysa_dish_pairings (CASCADE)

uysa_menu_plans ── uysa_menu_plan_items (CASCADE)

uysa_customers ──┬── uysa_menu_plans (SET NULL)
                 └── uysa_deliveries (SET NULL)
```

## 5. Test Yapisi

| Suite | Dosya | Test Sayisi | Kapsam |
|-------|-------|-------------|--------|
| Unit | UnitTest.php | ~30 | JWT, RateLimiter, core |
| Module | ModuleTest.php | 20 | Syntax, handler, schema |
| Catering | CateringModuleTest.php | 49 | COZBIM CRUD, HACCP, validation |
| Integration | IntegrationTest.php | ~10 | API HTTP, DB SQLite |

## 6. CI/CD Pipeline

```
quality → test ────→ docker → deploy (sadece main)
        ↘ security ↗
        ↘ performance
```

| Job | Icerik |
|-----|--------|
| quality | PHP lint, modul kontrol, schema validation, credential scan |
| test | Unit + Module + Catering + Integration testleri |
| security | Dependency audit, SQL injection scan, exec scan, header kontrol |
| docker | Image build + container health check + API smoke test |
| performance | Dosya boyutu + pattern kontrol |
| deploy | Railway CLI deploy + post-deploy health check |
