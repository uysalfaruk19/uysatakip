# UYSA ERP — v5 Migration & Deploy Rehberi

## 1. Genel Bakis

v5 guncellemesi, COZBIMESKi catering entegrasyonunu icerir:
- 8 yeni tablo (dishes, recipe_lines, menu_plans, menu_plan_items, parties, deliveries, haccp_logs, dish_pairings)
- CateringModule.php backend API (cat.* prefix)
- 49 yeni unit test

## 2. Gereksinimler

| Gereksinim | Minimum |
|------------|---------|
| PHP | 8.2+ |
| MySQL | 8.0+ |
| Disk | 100MB ek alan |
| Node.js | Gerekli degil (vanilla JS frontend) |

## 3. Schema Migrasyonu

### 3.1 Yeni Kurulum (Temiz DB)

```bash
mysql -u root -p uysa_db < sql/schema_v5.sql
```

### 3.2 Mevcut v4 DB Uzerine Guncelleme

v4'ten v5'e gecis icin sadece COZBIM tablolarini ekleyin:

```sql
-- COZBIM tablolari (v4 uzerine ekleme)
-- Sirasini koruyun: FK bagimliliklari icin once dishes, sonra recipe_lines/menu_plans

CREATE TABLE IF NOT EXISTS uysa_dishes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    category    ENUM('ana_yemek','corba','salata','tatli','meze','icecek','aperatif','diger') NOT NULL DEFAULT 'ana_yemek',
    sub_category VARCHAR(50),
    unit        VARCHAR(20) NOT NULL DEFAULT 'porsiyon',
    portion_gram DECIMAL(8,2),
    calorie     INT UNSIGNED,
    allergens   JSON,
    dish_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_price  DECIMAL(12,2),
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dishes_category (category),
    INDEX idx_dishes_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_recipe_lines (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dish_id     BIGINT UNSIGNED NOT NULL,
    ingredient  VARCHAR(150) NOT NULL,
    quantity    DECIMAL(10,3) NOT NULL,
    unit        VARCHAR(20) NOT NULL DEFAULT 'gram',
    unit_price  DECIMAL(12,4),
    sort_order  INT NOT NULL DEFAULT 0,
    notes       VARCHAR(255),
    FOREIGN KEY (dish_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE,
    INDEX idx_rl_dish (dish_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_menu_plans (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_date   DATE NOT NULL,
    meal_type   ENUM('kahvalti','ogle','aksam','ara_ogun') NOT NULL DEFAULT 'ogle',
    customer_id BIGINT UNSIGNED,
    week_no     TINYINT UNSIGNED,
    status      ENUM('draft','confirmed','served','cancelled') NOT NULL DEFAULT 'draft',
    created_by  VARCHAR(50),
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES uysa_customers(id) ON DELETE SET NULL,
    UNIQUE KEY uq_plan (plan_date, meal_type, customer_id),
    INDEX idx_mp_date (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_menu_plan_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id     BIGINT UNSIGNED NOT NULL,
    dish_id     BIGINT UNSIGNED NOT NULL,
    slot        ENUM('corba','ana','yan','salata','tatli','icecek','diger') NOT NULL DEFAULT 'ana',
    portion_count INT UNSIGNED,
    sort_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (plan_id) REFERENCES uysa_menu_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (dish_id) REFERENCES uysa_dishes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_parties (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20) NOT NULL UNIQUE,
    name        VARCHAR(200) NOT NULL,
    type        ENUM('musteri','tedarikci','diger') NOT NULL DEFAULT 'musteri',
    tax_no      VARCHAR(11),
    tax_office  VARCHAR(100),
    address     TEXT,
    city        VARCHAR(50),
    phone       VARCHAR(30),
    email       VARCHAR(255),
    iban        VARCHAR(34),
    balance     DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit_limit DECIMAL(14,2),
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parties_type (type),
    INDEX idx_parties_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_dish_pairings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dish_a_id   BIGINT UNSIGNED NOT NULL,
    dish_b_id   BIGINT UNSIGNED NOT NULL,
    score       TINYINT UNSIGNED NOT NULL DEFAULT 5,
    reason      VARCHAR(255),
    FOREIGN KEY (dish_a_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE,
    FOREIGN KEY (dish_b_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_pair (dish_a_id, dish_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_deliveries (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_no VARCHAR(30) NOT NULL UNIQUE,
    delivery_date DATE NOT NULL,
    customer_id BIGINT UNSIGNED,
    meal_type   ENUM('kahvalti','ogle','aksam','ara_ogun') NOT NULL DEFAULT 'ogle',
    vehicle     VARCHAR(50),
    driver      VARCHAR(100),
    portion_count INT UNSIGNED NOT NULL DEFAULT 0,
    departure_time TIME,
    arrival_time TIME,
    temperature DECIMAL(5,2),
    status      ENUM('planned','loading','in_transit','delivered','cancelled') NOT NULL DEFAULT 'planned',
    recipient   VARCHAR(100),
    signature   TINYINT(1) NOT NULL DEFAULT 0,
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES uysa_customers(id) ON DELETE SET NULL,
    INDEX idx_dlv_date (delivery_date),
    INDEX idx_dlv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uysa_haccp_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_date    DATE NOT NULL,
    log_time    TIME,
    check_point VARCHAR(100) NOT NULL,
    check_type  ENUM('temperature','humidity','visual','cleaning','pest','other') NOT NULL DEFAULT 'temperature',
    value       DECIMAL(8,2),
    min_limit   DECIMAL(8,2),
    max_limit   DECIMAL(8,2),
    is_ok       TINYINT(1) NOT NULL DEFAULT 1,
    corrective_action TEXT,
    checked_by  VARCHAR(100),
    equipment   VARCHAR(100),
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_haccp_date (log_date),
    INDEX idx_haccp_point (check_point),
    INDEX idx_haccp_ok (is_ok)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Collation Notu

v4 tablolari `utf8mb4_0900_ai_ci`, v5 tablolari `utf8mb4_unicode_ci` kullanir.
Ayni DB'de karisik collation sorun yaratmaz; JOIN'lerde MySQL otomatik convert eder.

## 4. Backend Deploy

### 4.1 Dosya Degisiklikleri

| Dosya | Islem |
|-------|-------|
| `public/src/modules/CateringModule.php` | YENI — kopyalanmali |
| `public/uysa_api.php` | GUNCELLEME — moduleMap'e `cat.` eklendi |
| `sql/schema_v5.sql` | GUNCELLEME — 8 yeni tablo |

### 4.2 Railway Deploy

```bash
# Branch'i push edin, CI/CD otomatik tetiklenir
git push origin main

# veya manuel Railway CLI ile:
railway up --detach
```

### 4.3 Docker Local Test

```bash
docker build -t uysa-erp:v5 .
docker run -d -p 8080:8080 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3306 \
  -e DB_NAME=uysa_db \
  -e DB_USER=root \
  -e DB_PASS=your_password \
  -e API_TOKEN=your_token \
  uysa-erp:v5
```

## 5. API Endpoint'leri (Yeni)

Tum COZBIM endpoint'leri `cat.` prefix kullanir:

| Action | Method | Aciklama |
|--------|--------|----------|
| `cat.dishes` | GET | Yemek listesi (search, category, is_active filtreleri) |
| `cat.dishGet` | GET | Tek yemek + recete satirlari |
| `cat.dishSave` | POST | Yemek ekle/guncelle |
| `cat.dishDelete` | POST | Yemek sil |
| `cat.recipeLines` | GET | Recete satirlari (dish_id ile) |
| `cat.recipeLineSave` | POST | Recete satiri ekle/guncelle |
| `cat.recipeLineDelete` | POST | Recete satiri sil |
| `cat.recipeBulkSave` | POST | Tum recete satirlarini toplu kaydet |
| `cat.menuPlans` | GET | Menu plan listesi (tarih, ogun, musteri filtreleri) |
| `cat.menuPlanGet` | GET | Tek menu plani + yemek kalemleri |
| `cat.menuPlanSave` | POST | Menu plani ekle/guncelle (items dahil) |
| `cat.menuPlanDelete` | POST | Menu plani sil |
| `cat.parties` | GET | Cari hesap listesi |
| `cat.partyGet` | GET | Tek cari hesap |
| `cat.partySave` | POST | Cari hesap ekle/guncelle |
| `cat.partyDelete` | POST | Cari hesap sil |
| `cat.deliveries` | GET | Teslimat listesi |
| `cat.deliverySave` | POST | Teslimat ekle/guncelle |
| `cat.deliveryDelete` | POST | Teslimat sil |
| `cat.haccpLogs` | GET | HACCP kayitlari |
| `cat.haccpLogSave` | POST | HACCP kaydi ekle/guncelle |
| `cat.dishPairings` | GET | Yemek eslesmeleri |
| `cat.dishPairingSave` | POST | Yemek eslesmesi ekle/guncelle |
| `cat.dashboard` | GET | Dashboard istatistikleri |

## 6. Rollback Proseduru

v5 tabloları mevcut v4 tablolarına dokunmaz. Geri almak icin:

```sql
DROP TABLE IF EXISTS uysa_menu_plan_items;
DROP TABLE IF EXISTS uysa_dish_pairings;
DROP TABLE IF EXISTS uysa_recipe_lines;
DROP TABLE IF EXISTS uysa_menu_plans;
DROP TABLE IF EXISTS uysa_deliveries;
DROP TABLE IF EXISTS uysa_haccp_logs;
DROP TABLE IF EXISTS uysa_dishes;
DROP TABLE IF EXISTS uysa_parties;
```

`uysa_api.php`'deki `$moduleMap`'ten `cat.` satirini cikartin.

## 7. Test Dogrulama

```bash
# Tum testleri calistir
composer test

# Sadece catering testleri
vendor/bin/phpunit tests/CateringModuleTest.php --testdox

# Sadece modul syntax/handler testleri
vendor/bin/phpunit tests/ModuleTest.php --testdox
```

Beklenen: 49 catering test, 20 module test, hepsi PASS.

## 8. Kontrol Listesi

- [ ] Schema migrasyonu calistirildi (3.1 veya 3.2)
- [ ] `CateringModule.php` dosyasi `public/src/modules/` altinda
- [ ] `uysa_api.php` moduleMap'te `cat.` girisi var
- [ ] `cat.dashboard` endpoint'i calisir
- [ ] HACCP kaydi olusturulabilir
- [ ] Yemek CRUD islemi yapilabilir
- [ ] Testler gecti (`composer test`)
