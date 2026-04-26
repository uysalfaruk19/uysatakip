# UYSA ERP - Changelog

## [5.0.0] - 2026-04-26

### Added
- **COZBIM Catering Entegrasyonu** (8 yeni tablo, 24 API endpoint)
  - Yemek katalog yonetimi (dishes, recipe_lines)
  - Menu planlama (menu_plans, menu_plan_items)
  - Cari hesap takibi (parties)
  - Teslimat/sefer yonetimi (deliveries)
  - HACCP gida guvenligi kayitlari (haccp_logs)
  - Yemek eslestirme puanlama (dish_pairings)
- CateringModule.php — cat.* prefix backend API
- 49 unit test (CateringModuleTest.php) — SQLite-based CRUD, HACCP logic, validation
- CI/CD pipeline guncelleme — CateringModule syntax/handler, COZBIM tablo validation
- v5 Migration & Deploy rehberi (docs/MIGRATION_v5.md)
- COZBIM referans analiz dokumani (docs/COZBIM_REFERANS.md)

### Fixed
- Dish code regex: buyuk harf Turkce karakterler (C, S, U, O) eklendi
- Borclar sekmesi giderler/faturalar gostermiyor — _addBorc() 4 noktaya eklendi
- 16 timezone hatasi: toISOString().slice(0,10) yerine yerel tarih formatlama
- 5 localStorage key uyumsuzlugu (musteriler, recipes, butce, uretim_gider, gunluk_sayilar)
- 23 tanimsiz onclick handler — stub fonksiyonlar eklendi
- HTML yapisi: kapatilmamis label, yanlis data-mod selector
- Duplicate XLSX kutuphane (0.20.1 silindi, 0.20.3 kaldi)
- Duplicate inferTypeFromDish fonksiyonu kaldirildi

### Changed
- schema_v5.sql: 43 -> 52 tablo (8 COZBIM + 1 menu_plan_items)
- phpunit.xml: Module ve Catering test suite'leri eklendi
- uysa_api.php moduleMap: cat. prefix -> CateringModule.php
- ci.yml: COZBIM modul + tablo kontrolu, CateringModuleTest adimi
