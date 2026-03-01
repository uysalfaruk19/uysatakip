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

## 📊 Modüller

1. 🏠 **Anasayfa** — Maliyet Merkezi Dashboard
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
