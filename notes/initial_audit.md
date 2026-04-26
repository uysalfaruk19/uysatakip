# UYSA ERP -- Initial Codebase Audit

**Date:** 2026-04-26  
**Total lines (code files, excl. vendor):** ~51,875

---

## 1. File Structure

```
uysatakip/
├── .env.example                  49 lines
├── .gitignore                    30 lines
├── composer.json                 36 lines
├── composer.lock                 (generated)
├── Dockerfile                    22 lines
├── phpunit.xml                   44 lines
├── railway.toml                  10 lines
├── README.md                    137 lines
├── .github/
│   └── workflows/
│       └── ci.yml               429 lines
├── docs/
│   └── COZBIM_REFERANS.md       108 lines
├── notes/                        (this audit)
├── public/
│   ├── .htaccess                 41 lines
│   ├── index.html            26,337 lines  (1,430,789 bytes)
│   ├── uysa_api.php           1,763 lines  (90,101 bytes)
│   ├── health.php                44 lines
│   ├── api-docs.html            268 lines
│   ├── uysa_migrate.html        331 lines
│   ├── manifest.json              9 lines
│   ├── uysa-logo.svg            (asset)
│   ├── uysa-ux-manager.js       544 lines
│   ├── style_v118.css        13,516 lines
│   ├── style_v119.css             0 lines  (empty)
│   ├── sw.js                     69 lines
│   ├── sw_v119.js                 0 lines  (empty)
│   ├── sw_v120.js               141 lines
│   ├── uploads/
│   │   ├── .htaccess              2 lines
│   │   └── .gitkeep
│   ├── src/
│   │   ├── ApiKeyManager.php    141 lines
│   │   ├── JwtManager.php       128 lines
│   │   ├── RateLimiter.php      160 lines
│   │   └── modules/
│   │       ├── FinanceModule.php    480 lines
│   │       ├── HRModule.php         533 lines
│   │       ├── InventoryModule.php  428 lines
│   │       ├── PortalModule.php     504 lines
│   │       └── TelegramBot.php      534 lines
│   └── js/
│       ├── recete-core.js       129 lines
│       ├── recete-kutuphane.js  695 lines
│       ├── recete-gunluk.js     851 lines
│       ├── recete-analiz.js     270 lines
│       ├── rapor-aylik-ozet.js  316 lines
│       ├── patron-sayfasi.js    323 lines
│       ├── irsaliye.js          226 lines
│       └── mesai-takip.js       176 lines
├── sql/
│   ├── schema.sql               160 lines
│   ├── schema_v4.sql            213 lines
│   └── schema_v5.sql            778 lines
└── tests/
    ├── UnitTest.php             401 lines
    ├── ModuleTest.php           193 lines
    └── IntegrationTest.php      306 lines
```

---

## 2. public/index.html

- **Size:** 1,430,789 bytes (~1.4 MB), 26,337 lines
- **Script blocks:** 40 `<script>` tags total
  - 2 inline bootstrap scripts (service worker cleanup, load handler)
  - 2 CDN scripts: SheetJS (xlsx), Chart.js
  - 8 external JS files loaded with `defer` (uysa-ux-manager.js + 7 from public/js/)
  - ~28 inline `<script>` blocks containing all app logic
- **Major IIFEs / modules embedded inline:**
  - **Sync Engine** (line ~1102): offline queue, localStorage intercept, server pull/push, exponential backoff retry, export/import
  - **Toast / notification system** (line ~5333)
  - **Menu planner** (line ~5852): weekly menu grid, Excel import, recipe linking
  - **Recipe module helpers** (line ~6200): monthly view, JSON download
  - **Finance module** (line ~8383): tab switching (dashboard/gelir/gider/uretim/sayi/ozet/butce/alacak/borc/patron), KPI cards, tedarikci dropdowns, daily counts
  - **Inventory/stock panel** (line ~9220): depot management, stock movements, counting
  - **CRM panel** (line ~9575): customer add/rename, contract management, personnel selection
  - **Login overlay** (line ~1927): auth UI with `uysaDoLogin`
  - **Security module** (line ~24760): security-v1 block
  - **UX Manager / top-fix** (line ~24918)
- **Navigation modules (mod-nav):** anasayfa, finans, operasyon, satis, satinalma, stok, ik, raporlama, recete

---

## 3. public/uysa_api.php

- **Size:** 90,101 bytes, 1,763 lines
- **37 core actions** in the main switch, plus module delegation:

| Category | Actions |
|----------|---------|
| Health | `ping`, `health`, `stats` |
| AI/Auth | `ai.providerAuthStart`, `ai.providerAuthStatus`, `ai.verifyDevice`, `ai.providerAuthLogout` |
| Token/JWT | `getToken`, `refreshToken` |
| API Keys | `apiKeyCreate`, `apiKeyList`, `apiKeyRevoke` |
| Storage | `get`, `set`, `setBulk`, `delete`, `getAll` |
| Backup | `backup`, `backupList`, `backupRestore` |
| Users | `userAuth`, `userList`, `userSave` |
| Audit | `auditLog`, `auditList` |
| Files | `fileList`, `fileDownload`, `fileDelete`, `fileUpload` |
| Telegram | `telegram.webhook`, `telegram.setup` |
| AI Chat | `ai.chat`, `ai.history`, `ai.bridgeProxy` |

Module actions are delegated to files in `public/src/modules/`:
- `fin.*` -> FinanceModule.php (21 actions)
- `inv.*` -> InventoryModule.php (19 actions)
- `hr.*` -> HRModule.php (27 actions)
- `portal.*` -> PortalModule.php (19 actions)

---

## 4. public/src/ -- PHP Classes

| File | Lines | Purpose |
|------|-------|---------|
| `ApiKeyManager.php` | 141 | API key generation (192-bit), hash storage, verification, revocation, scope checking |
| `JwtManager.php` | 128 | HS256 stateless JWT: issue, verify, refresh; min 32-char secret |
| `RateLimiter.php` | 160 | Sliding-window rate limiter (MySQL-backed), auto-lock after max attempts |

### public/src/modules/

| File | Lines | Purpose |
|------|-------|---------|
| `FinanceModule.php` | 480 | Double-entry accounting, chart of accounts, journal entries, invoices, payments, bank accounts/transactions, trial balance, balance sheet, income statement, dashboard |
| `HRModule.php` | 533 | Employees, leave types/requests/approval, attendance, shifts/assignments, payroll calculation, performance reviews, trainings, org chart, dashboard |
| `InventoryModule.php` | 428 | Multi-warehouse, products, suppliers, stock movements, purchase orders (with receive flow), lot tracking, low-stock alerts, stock reports, supplier comparison, dashboard |
| `PortalModule.php` | 504 | Customer portal login, customer CRUD, portal orders, invoices, profile, webhooks (create/test/delete), 2FA (TOTP setup/verify/disable) |
| `TelegramBot.php` | 534 | Webhook-based bot handler, user linking, command routing (stok/satis/personel/rapor/yardim), inline keyboards, daily summary broadcasts |

---

## 5. public/js/ -- JavaScript Files

| File | Lines | Purpose |
|------|-------|---------|
| `recete-core.js` | 129 | Recipe module core: localStorage helpers, storage keys, shared utility (`window._rc`) |
| `recete-kutuphane.js` | 695 | Recipe catalog: categorized listing, recipe CRUD, category labels |
| `recete-gunluk.js` | 851 | Daily production: consumption-based tracking, meal name normalization |
| `recete-analiz.js` | 270 | Cost analysis: date-range filtering, recipe history |
| `rapor-aylik-ozet.js` | 316 | Monthly summary report: supplier, customer, personnel, unit cost |
| `patron-sayfasi.js` | 323 | Executive dashboard: period P&L, unit cost, customer revenue, supplier analysis (uses Chart.js) |
| `irsaliye.js` | 226 | Delivery notes (irsaliye): auto-generation from menu, document numbering |
| `mesai-takip.js` | 176 | Overtime tracking: clock in/out, standard 8h day, overtime calculation |

All files are IIFEs using `'use strict'` and localStorage for persistence.

---

## 6. sql/ -- Schema Files

| File | Lines | Tables | Description |
|------|-------|--------|-------------|
| `schema.sql` | 160 | 6 | Original v3: `uysa_storage`, `uysa_backups`, `uysa_logs`, `uysa_audit`, `uysa_users`, `uysa_files` |
| `schema_v4.sql` | 213 | 10 | Adds: `uysa_rate_limits`, `uysa_rate_locks`, `uysa_api_keys`, `uysa_sessions` |
| `schema_v5.sql` | 778 | 43 | Full ERP schema. Adds 33 tables for finance (accounts, journal_entries/lines, invoices/lines, payments, bank_accounts/transactions), inventory (warehouses, suppliers, products, stock_movements, purchase_orders/lines, lots), HR (employees, leave_types/requests, attendance, shifts/assignments, payroll, performance_reviews, trainings/participants), portal (customers, customer_orders/lines, webhooks/logs, 2fa), AI (ai_chats), Telegram (telegram_users) |

---

## 7. Tests

### Test files

- **UnitTest.php** (401 lines) -- 4 test classes:
  - `JwtManagerTest` (10 tests): issue, verify, tamper, expire, refresh, unique JTI
  - `RateLimiterTest` (5 tests): allow, block, reset, status, key independence
  - `ApiKeyManagerTest` (8 tests): create, hash storage, verify, revoke, scopes, wildcard, list
  - `SecurityTest` (5 tests): JWT secret length, XSS sanitization, SQL injection prevention, password hashing, random bytes

- **ModuleTest.php** (193 lines) -- 1 test class:
  - `ModuleTest` (18 tests): syntax validation for all modules + main API, handler existence, Base32 generation/decode, schema v5 validation, payroll calculation, working days, file existence

- **IntegrationTest.php** (306 lines) -- 2 test classes:
  - `ApiIntegrationTest` (11 tests, skipped without live server): health, token validation, ping, storage CRUD, bulk ops, rate limiting, security headers, auth, backup
  - `DatabaseIntegrationTest` (4 tests): SQLite-backed insert/select, upsert, audit log, transaction rollback

### Test results

```
$ composer install --no-interaction 2>&1 | tail -5
  → 26 packages installed (PSR-4 warnings for multi-class files)

$ ./vendor/bin/phpunit tests/ --testdox 2>&1 | tail -30
  Module:
    ✔ All 18 module tests pass
  
  Warnings:
    1) Class UnitTest cannot be found (PSR-4 naming mismatch)
    2) No code coverage driver available

  Result: Tests: 28, Assertions: 55, Warnings: 2, Skipped: 10
```

- **28 tests run, 55 assertions, all pass**
- **10 tests skipped** (integration tests requiring a live server)
- **2 warnings:** PSR-4 class naming mismatch in UnitTest.php; no coverage driver (xdebug/pcov not installed)
- UnitTest classes are not picked up due to PSR-4 non-compliance (file has multiple classes: JwtManagerTest, RateLimiterTest, ApiKeyManagerTest, SecurityTest -- none named `UnitTest`)

---

## 8. .github/workflows/ci.yml

**429 lines**, 6 jobs in the pipeline:

1. **quality** -- PHP lint, module file existence, schema v5 validation, hardcoded credential scan, .env not committed, file size check, HTML structure validation
2. **test** (needs: quality) -- Unit tests + coverage (clover), Module tests, Integration tests (SQLite), coverage threshold check (60% minimum)
3. **security** (needs: quality) -- `composer audit`, static analysis for raw SQL/eval/exec/shell functions, security header verification
4. **docker** (needs: test + security) -- Docker build, container health check, API smoke test (expects 403 without token)
5. **performance** (needs: quality) -- Asset size report, defer/async count, lazy loading, content-visibility usage
6. **deploy** (needs: docker, main branch only) -- Railway CLI install + deploy, post-deploy health check, summary to GitHub step summary

Triggers: push to main/develop/claude/**, PRs to main, manual dispatch.  
Concurrency control with cancel-in-progress.

---

## 9. composer.json

```json
{
  "name": "uysa-erp/tests",
  "require": { "php": ">=8.2" },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "roave/security-advisories": "dev-latest"
  },
  "autoload":     { "psr-4": { "Uysa\\": "src/" } },
  "autoload-dev": { "psr-4": { "Uysa\\Tests\\": "tests/" } },
  "scripts": {
    "test":             "vendor/bin/phpunit tests/ --testdox --colors=always",
    "test-unit":        "vendor/bin/phpunit tests/UnitTest.php --testdox",
    "test-integration": "vendor/bin/phpunit tests/IntegrationTest.php --group integration",
    "test-coverage":    "vendor/bin/phpunit tests/UnitTest.php --coverage-text --coverage-html coverage/",
    "test-ci":          "vendor/bin/phpunit tests/ --testdox --log-junit test-results.xml"
  }
}
```

**Issues:**
- Autoload PSR-4 maps `Uysa\` to `src/` (root), but PHP classes live in `public/src/`. They are not namespaced and not autoloaded -- they are `require`d directly by `uysa_api.php`.
- Test classes have PSR-4 mismatches (multiple classes per file, class names don't match filenames).

---

## 10. Missing Pieces for v5

Based on the v5 schema, modules, and the existing codebase:

### Already built (back-end)
- v5 schema with 43 tables
- Finance, HR, Inventory, Portal, Telegram PHP modules
- JWT, API Key, Rate Limiter classes
- CI pipeline with 6 jobs

### Missing or incomplete

| Gap | Detail |
|-----|--------|
| **Front-end for v5 modules** | The front-end (index.html) is still v3-era. Finance tab uses localStorage-based gelir/gider; no UI connects to `fin.*`, `hr.*`, `inv.*`, `portal.*` server-side endpoints. All v5 module PHP endpoints exist but have no corresponding front-end integration. |
| **PSR-4 autoloading** | Classes in `public/src/` are not namespaced; composer autoload path (`src/`) doesn't match actual location (`public/src/`). Unit tests can't autoload the classes. |
| **Test coverage for v5 modules** | Module tests only check syntax and handler existence. No functional tests for any of the 86 module endpoints (fin/inv/hr/portal). |
| **Database migration tooling** | No migration scripts from v4 -> v5. Only full schema files exist; no incremental ALTER TABLE migrations. |
| **2FA front-end** | Portal module supports TOTP 2FA in PHP but there is no corresponding UI in index.html. |
| **Webhook delivery** | `PortalModule` has webhook CRUD and test endpoints but no actual event-driven webhook dispatch (e.g., on order status change). |
| **Role-based access control** | Module endpoints check `$authedUser` loosely. No granular permission system matching the v5 scope model in ApiKeyManager. |
| **SPA routing** | index.html is a single monolithic file (1.4 MB). No code splitting, lazy loading of modules, or route-based rendering. |
| **Empty files** | `style_v119.css` and `sw_v119.js` are 0 bytes (likely superseded by v120 but still referenced or deployed). |
| **README outdated** | README says "v3.0" with 845KB index.html; actual is v5-era with 1.4 MB. |
| **Environment/config separation** | index.html appears to have a hardcoded `CFG.token`; the CI checks for this but it's a security concern. |
| **No e2e / browser tests** | No Playwright, Cypress, or similar. Front-end logic (26K lines of inline JS) is untested. |
| **Service worker inconsistency** | Three SW files exist (sw.js, sw_v119.js empty, sw_v120.js). The bootstrap script in index.html unregisters all service workers on load. |

---

*End of audit.*
