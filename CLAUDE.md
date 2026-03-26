# CLAUDE.md — UYSA ERP v3.0

## Project Overview

UYSA ERP v3.0 is a restaurant/food-industry management system (Turkish ERP). It's a single-page application with a PHP REST API backend, MySQL database, deployed on Railway.

**Production URL:** `https://uysatakip.production.up.railway.app`

## Tech Stack

- **Frontend:** Vanilla JavaScript SPA — single `public/index.html` file (~21K lines, ~845KB) with embedded CSS/JS
- **Backend:** PHP 8.2 — `public/uysa_api.php` (REST API, no framework)
- **Database:** MySQL 8.0 via PDO (raw prepared statements, no ORM)
- **Auth:** JWT (HS256) + API Keys (SHA3-256) + legacy token fallback
- **Deployment:** Docker → Railway PaaS
- **CI/CD:** GitHub Actions (`.github/workflows/ci.yml`)
- **Tests:** PHPUnit 11

## Repository Structure

```
├── public/
│   ├── index.html           # Full SPA frontend (all 14 modules)
│   ├── uysa_api.php         # REST API backend (~851 lines)
│   ├── health.php           # Health check endpoint
│   ├── manifest.json        # PWA manifest
│   ├── .htaccess            # Apache rewriting & security headers
│   └── src/
│       ├── ApiKeyManager.php  # API key generation & validation
│       ├── JwtManager.php     # JWT token management (HS256)
│       └── RateLimiter.php    # Sliding window rate limiting
├── sql/
│   └── schema.sql           # MySQL schema (tables, views, indexes)
├── tests/
│   ├── UnitTest.php         # Unit tests (JWT, API keys, rate limiter)
│   └── IntegrationTest.php  # API integration tests
├── Dockerfile               # PHP 8.2-cli container
├── railway.toml             # Railway deployment config
├── composer.json            # PHP dependencies & test scripts
├── phpunit.xml              # PHPUnit configuration
└── .env.example             # Environment variable template
```

## Common Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run unit tests only
composer test-unit

# Run integration tests (requires running API at TEST_API_URL)
composer test-integration

# Generate coverage report (HTML output)
composer test-coverage

# CI test output (JUnit XML)
composer test-ci

# Start local dev server
php -S localhost:8080 -t public/

# Build Docker image
docker build -t uysa-erp .

# Run Docker container
docker run -p 8080:8080 --env-file .env uysa-erp
```

## Architecture & Patterns

### API Design

The API is action-based via query parameter: `uysa_api.php?action=<action>`

**Response format:**
```json
{ "ok": true, "data": { ... } }
{ "ok": false, "error": "message" }
```

**38 endpoints** organized by domain: storage CRUD, backup/restore, user management, JWT auth, API keys, file uploads, audit logging, system health.

### Authentication (checked in order)

1. **JWT Bearer token** — `Authorization: Bearer <token>` (preferred)
2. **API Key** — `X-UYSA-Token: uysa_<hex>` (service-to-service)
3. **Legacy token** — `X-UYSA-Token: <API_TOKEN>` (deprecated fallback)

### Authorization Roles

- `superadmin` — Full access including user/API-key management
- `editor` — Data CRUD + user management
- `user` — Data CRUD
- `viewer` — Read-only

**Public endpoints** (no auth): `ping`, `health`, `stats`, `getToken`, `userAuth`, `fileDownload`

### Rate Limiting

Sliding window algorithm on auth endpoints: 10 attempts per 10 minutes, 15-minute lockout. Backed by `uysa_rate_limits` and `uysa_rate_locks` tables.

### Database

8 tables + 3 views in MySQL. Key tables: `uysa_storage` (key-value store), `uysa_users`, `uysa_files` (soft-delete), `uysa_audit`, `uysa_backups`, `uysa_api_keys`, `uysa_rate_limits`, `uysa_rate_locks`.

All queries use PDO prepared statements. No ORM — raw SQL throughout.

### Frontend

Single-file SPA with 14 business modules (dashboard, menus, finance, warehouse, sales, purchasing, HR, reporting, documents, production, HACCP, logistics, notifications, portal). Modules are `mod-panel` divs toggled via CSS `active` class. Uses localStorage with MySQL sync.

## Code Conventions

- **PHP:** PSR-4 autoloading (`Uysa\` → `src/`, `Uysa\Tests\` → `tests/`)
- **Tests:** PHPUnit 11 attributes (`#[Test]`, `#[CoversClass]`)
- **Input sanitization:** All user input passes through `sanitizeInput($val, $maxLen)` in the API
- **Passwords:** bcrypt with cost 12 + timing-attack-safe verification
- **Files:** Whitelist-based extension/MIME validation, safe filename generation (timestamp + random hex)
- **Security headers:** X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy
- **Soft-delete:** Files use `deleted_at` instead of physical deletion
- **Audit logging:** All destructive operations are logged to `uysa_audit`

## CI/CD Pipeline

The GitHub Actions pipeline runs on push to `main`/`develop` and PRs to `main`:

1. **Code Quality** — PHP syntax lint, credential scanning, file structure validation
2. **Tests** — PHPUnit with 60% minimum coverage threshold
3. **Security** — Composer audit, SQL injection pattern detection, eval/system usage check, security header verification
4. **Docker** — Build image + container health check + API smoke test
5. **Performance** — Asset size reporting, script optimization checks
6. **Deploy** — Railway deployment (main branch only), post-deploy health check

**Required secret:** `RAILWAY_TOKEN` for deployment.

## Environment Variables

Copy `.env.example` to `.env`. Key variables:

| Variable | Description |
|----------|-------------|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection |
| `API_TOKEN` | Legacy auth token |
| `JWT_SECRET` | Secret for JWT signing (min 32 chars) |
| `BACKUP_MAX` | Max backup retention count (default: 30) |
| `UPLOAD_DIR` | File upload directory (default: `/app/public/uploads`) |
| `UPLOAD_MAX_MB` | Max upload size in MB (default: 25) |
| `CORS_ORIGINS` | Allowed CORS origins |

Railway uses `${{MySQL.*}}` variable interpolation for database credentials.

## Important Notes for AI Assistants

- **index.html is very large** (~845KB). Read only the sections you need — avoid reading the entire file.
- **No frontend build step.** All JS/CSS is inline in `index.html`.
- **No framework.** Both frontend and backend are vanilla — no React, no Laravel, no Express.
- **Turkish language.** UI text, comments, and some variable names are in Turkish.
- **Single API file.** All backend logic routes through `uysa_api.php` with a `switch` on `$action`.
- **Test coverage threshold** is 60%. Don't let changes drop below this.
- **Security is critical.** Always use prepared statements, sanitize input, validate file types. The CI pipeline scans for SQL injection patterns and dangerous function calls.
- **Deployment** is automatic on push to `main`. Test changes thoroughly before merging.
