# UYSA ERP v3.0 🍽️

Yemek Sektörü Yönetim Sistemi — Railway + MySQL Production Deploy

## 🏗️ Mimari

```
uysa-project/
├── public/
│   ├── index.html        # Ana ERP uygulaması (845KB, 14 modül)
│   ├── uysa_api.php      # PHP API v3.0 (storage, auth, file, audit)
│   ├── uysa_migrate.html # Veri migration paneli
│   ├── health.php        # Railway health check
│   ├── .htaccess         # Apache URL rewriting
│   └── uploads/          # Yüklenen dosyalar (Railway volume)
├── sql/
│   └── schema.sql        # MySQL schema v3.0
├── Dockerfile            # PHP 8.2 + Apache
├── railway.toml          # Railway konfigürasyonu
├── .env.example          # Ortam değişkenleri şablonu
└── .github/workflows/    # CI/CD
```

## 👥 Kullanıcılar

| Kullanıcı | Rol | Yetki |
|-----------|-----|-------|
| OFU | 👑 Süper Yönetici | Tüm işlemler + kullanıcı yönetimi |
| Azim | 👤 Standart Kullanıcı | Okuma + yazma |

> **İlk giriş şifreleri:**
> - OFU: `05321608119` (telefon numarası — ilk girişten sonra değiştirin!)
> - Azim: `Azim2024!`

## 🚀 Kurulum

### Railway Deploy

1. **GitHub'a yükle:**
```bash
git init
git add .
git commit -m "UYSA ERP v3.0 initial commit"
git remote add origin https://github.com/KULLANICI/uysa-erp.git
git push -u origin main
```

2. **Railway'de:**
   - "New Project" → "Deploy from GitHub Repo" seç
   - MySQL servisi ekle: "New" → "Database" → "MySQL"
   - Ortam değişkenlerini ekle (aşağıya bakın)

3. **Ortam değişkenleri (Railway Variables):**
```
DB_HOST     = ${{MySQL.MYSQLHOST}}
DB_PORT     = ${{MySQL.MYSQLPORT}}
DB_NAME     = ${{MySQL.MYSQLDATABASE}}
DB_USER     = ${{MySQL.MYSQLUSER}}
DB_PASS     = ${{MySQL.MYSQLPASSWORD}}
API_TOKEN   = <openssl rand -hex 32 ile oluşturun>
UPLOAD_DIR  = /app/public/uploads
UPLOAD_MAX_MB = 25
```

4. **index.html'de token güncelle:**
   - `CFG.token` değerini `API_TOKEN` ile eşleştirin

### Railway Volume (Dosya Kalıcılığı)
```
Mount Path: /app/public/uploads
```

## 📡 API Endpoints

### Storage (v2.1 uyumlu)
| Action | Method | Açıklama |
|--------|--------|----------|
| `getAll` | GET | Tüm verileri al |
| `get` | POST | Tek kayıt al |
| `set` | POST | Kayıt ekle/güncelle |
| `setBulk` | POST | Toplu kayıt |
| `delete` | POST | Kayıt sil (audit log!) |
| `backup` | POST | Yedek al |
| `backupList` | GET | Yedek listesi |
| `backupRestore` | POST | Yedek geri yükle |
| `stats` | GET | İstatistikler |
| `health` | GET | Sağlık kontrolü |

### Dosya Yönetimi (v3.0)
| Action | Method | Açıklama |
|--------|--------|----------|
| `fileUpload` | POST (multipart) | Dosya yükle (max 25MB) |
| `fileList` | GET | Dosya listesi (kategori filtreli) |
| `fileDownload` | GET | Dosya indir |
| `fileDelete` | POST | Soft-delete (audit log!) |

### Kullanıcı Yönetimi (v3.0)
| Action | Method | Açıklama |
|--------|--------|----------|
| `userAuth` | POST | Giriş yap |
| `userList` | GET | Kullanıcı listesi |
| `userSave` | POST | Kullanıcı ekle/güncelle |

### Audit Log (v3.0)
| Action | Method | Açıklama |
|--------|--------|----------|
| `auditLog` | POST | Log ekle |
| `auditList` | GET | Log listesi (filtreli) |

## 🔒 Güvenlik

- **API Token**: X-UYSA-Token header ile her istekte gönderilir
- **Bcrypt**: Kullanıcı şifreleri bcrypt(cost=10) ile hashlenir
- **Soft Delete**: Silinen dosyalar fiziksel olarak silinir ama DB kaydı audit için tutulur
- **Audit Log**: Tüm silme, giriş, kullanıcı değişiklik işlemleri loglanır
- **Upload Güvenliği**: İzin verilen tipler: pdf, doc, docx, xls, xlsx, jpg, png, txt, csv, zip
- **Upload Klasörü**: .htaccess ile direkt erişim engellenir

## 🔄 Otomatik Veri Akışı (Günlük İşlem Merkezi)

Sistem iki günlük işlem etrafında çalışır — her şey anasayfadan yapılır:

```
📊 Günlük yemek sayısı gir  ──→  GELİR otomatik oluşur (müşteri × kişi × fiyat)
🧾 Gelen faturayı işle      ──→  GİDER otomatik oluşur (kategori akıllı eşlenir)
```

- **Sayı girişi**: Anasayfa açılır açılmaz bugünün tablosu hazır; kişi sayıları
  bir önceki iş gününden, fiyatlar CRM'den otomatik dolu gelir. Tek tıkla
  kaydet → gelir kaydı oluşur. Aynı güne tekrar kayıt üzerine yazar (mükerrer olmaz).
  Kullanılan fiyat CRM varsayılanına geri yazılır (fiyat hafızası).
- **Fatura girişi**: Anasayfadan çok kalemli hızlı giriş veya Satın Alma
  modülünden kalem kalem. Her kalem otomatik gider yazılır; kategori ürün
  adından (tavuk → 🍗, domates → 🥦 …) veya tedarikçi kategorisinden tahmin
  edilir. Fatura kalemi silinirse bağlı gider de silinir. Birim fiyatlar
  maliyet kataloğuna otomatik aktarılır.
- **Rozetler**: Otomatik oluşan kayıtlar listelerde 📊 OTO / 🧾 FATURADAN
  rozetiyle görünür — elle girilenlerden ayırt edilir.
- **Aylık Sayı Çizelgesi**: Excel alışkanlığındaki gün × müşteri tablosu
  anasayfada. Hücreye kişi sayısını yaz → kayıt ve gelir otomatik güncellenir;
  hafta sonları H.S. olarak işaretli; CSV (Excel) dışa aktarım var.
- **Dönem seçici**: Dashboard varsayılan olarak içinde bulunulan ayı gösterir;
  Bu Ay / Geçen Ay / Bu Yıl / Tümü veya özel tarih aralığı seçilebilir.
- **Veri kaynağı izleme**: Herhangi bir KPI kartına veya grafikteki 🔍 Kaynak
  butonuna tıkla → o rakamı oluşturan kayıtlar (hangi anahtar, hangi girişten
  geldiği, otomatik/elle dağılımı) listelenir.
- **Müşteri bazlı ekstre & mutabakat**: Çizelgede müşteri seç → aylık ekstre
  görünümü (haftalık ara toplamlar); 📥 Excel (CSV) müşteriye özel iner,
  🖨️ Yazdır imzalı mutabakat formu üretir.
- **Cari takip**: Finans → 💳 Cari. Müşteri bakiyesi = tahakkuk − tahsilat,
  tedarikçi bakiyesi = fatura/gider − ödeme. Tahsilat/ödeme girişleri
  `uysa_odemeler` anahtarında tutulur, gelir/gideri değiştirmez. Her cari
  için yürüyen bakiyeli ekstre; anasayfada açık bakiye özeti.
- **Öğün bazlı sayılar**: Öğle / Akşam / Kahvaltı / İftar ayrı kolonlarda
  izlenir (`uysa_gunluk_uretim.ogun`; eski öğünsüz kayıtlar Öğle sayılır).
  Müşteriye ➕ ile öğün açılır, öğün fiyatları CRM'de ayrı hatırlanır
  (`crm.ogunler`), gelir kayıtları ve mutabakat çıktıları öğün etiketlidir.
- **Raporlama sadeleştirildi**: Mükerrer BI kartları kaldırıldı; Raporlama
  artık Anasayfa ile aynı dönem durumunu paylaşan analiz dönemi bandı
  kullanır. Müşteri Karlılık Skoru varsayılanı içinde bulunulan aydır.

> ⚠️ Fatura işledikten sonra aynı tutarı Finans'a elle gider olarak girmeyin —
> sistem zaten oluşturur.

## 📊 Modüller

1. 🏠 **Anasayfa** — Günlük İşlem Merkezi (sayı→gelir, fatura→gider) + Dashboard
2. 📋 **Menü & Üretim** — Haftalık menü planlama
3. 💰 **Finans** — Gelir/Gider/Bütçe
4. 🏭 **Depo & Stok** — Stok takibi
5. 🤝 **Satış & CRM** — Müşteri yönetimi
6. 🛒 **Satın Alma** — Tedarik ve faturalar
7. 👥 **İnsan Kaynakları** — Personel, puantaj, bordro
8. 📊 **Raporlama** — BI dashboard
9. 🗂️ **Doküman** — Dijital arşiv (sunucu yükleme)
10. 🍳 **Üretim** — KDS & Alerjen
11. 🛡️ **HACCP** — Gıda güvenliği
12. 🚚 **Lojistik** — Sefer & Teslimat
13. 🔔 **Bildirim** — Bildirim Merkezi
14. 🌐 **Portal** — Müşteri Portalı

---
*UYSA ERP v3.0 © 2025*
