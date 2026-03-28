# ÇözBİM YEMEKÇI(SQL) — Referans Analiz Dökümanı
## UYSA ERP Geliştirmesi İçin Sektör Benchmarkı

**Yazılım:** ÇözBİM YEMEKÇI(SQL) — Yemek Üretim Otomasyon Sistemi
**Sektör:** Kurumsal yemek üretimi / catering / toplu yemek
**Mimari:** Windows masaüstü uygulama (SQL Server tabanlı)

---

## UYSA vs ÇözBİM — Karşılaştırma Matrisi

| Modül | ÇözBİM | UYSA Mevcut | Eksik/Geliştirme |
|-------|--------|-------------|------------------|
| Kasa | Günlük kasa, devir, analiz | - | Kasa modülü yok |
| Stok Kontrol | FIFO, sayım fişi, stok extresi, min/max | Basit depo takip | FIFO, sayım, min/max seviye |
| Üretim/Reçete | Reçete, üretim fişi, hammadde sarf | Reçete modülü (yeni) | Üretim fişi, hammadde sarf otomatik |
| Mönüler | Mönü dağılımı, irsaliye, maliyet | Menü planlama | İrsaliye oluşturma |
| Personel | Puantaj, bordro, SSK, kıdem/ihbar | Basit personel listesi | Bordro, puantaj, SSK |
| Maliyetler | Patron sayfası, bilanço, K/Z, dönemsel | Aylık özet rapor (yeni) | Dönemsel analiz, patron sayfası |
| Cari Hesap | 4 seviye hiyerarşi, ekstre, mizan | Müşteri/tedarikçi basit | Ekstre, ağdat, stok dağılım |
| Fatura | Alış/satış, KDV, iskonto, irsaliye | Gelir/gider kaydı | Fatura yönetimi |
| Sipariş | Alınan/verilen, takip | - | Sipariş modülü |
| Muhasebe | Fiş, hesap planı, mizan, bilanço | - | Muhasebe entegrasyonu |
| Çek/Senet | Portföy, bordro, vade | - | Çek/senet takip |
| Servis/Araç | Araç kart, güzergah, km/yakıt | - | Araç takip |

## Öncelikli Geliştirme Önerileri (UYSA için)

### Kısa Vadeli (Mevcut altyapıya eklenebilir)
1. **Stok min/max seviye uyarıları** — Depo modülüne kritik seviye ekleme
2. **Reçete → Üretim fişi akışı** — Menüden otomatik hammadde hesaplama
3. **Patron Sayfası** — Dönemsel K/Z, birim maliyet, grafik
4. **Porsiyon bazlı maliyet** — Müşteri bazlı farklı porsiyon

### Orta Vadeli
5. **Cari hesap extresi** — Müşteri/tedarikçi bazlı detaylı ekstre
6. **İrsaliye oluşturma** — Menü → otomatik irsaliye akışı
7. **Personel puantaj** — Günlük giriş/çıkış, fazla mesai

### Uzun Vadeli
8. **Muhasebe entegrasyonu** — Basit fiş/mizan
9. **Sipariş yönetimi** — Tedarikçi sipariş takibi
10. **Çek/senet portföyü** — Vade takip

---

## ÇözBİM Temel İş Akışları

### Akış 1: Hammadde Satın Alma → Stok
```
Sipariş Fişi (Verilen Sipariş)
  → Mal Alış İrsaliyesi
  → Stok Giriş (FIFO/LIFO/Son Alış)
  → Fatura
  → Cari Hesap borç kaydı
```

### Akış 2: Üretim
```
Mönü Tanımı → Üretim Fişi
  → Hammadde Gereksinim Tablosu
  → Reçete × Porsiyon = Hammadde ihtiyacı
  → Stok düşülür → Maliyet hesaplanır
```

### Akış 3: Müşteri Teslimatı → Tahsilat
```
Mönü Dağılımı → İrsaliye → Fatura
  → Cari Hesap alacak → Tahsilat
```

### Akış 4: Personel Bordro
```
Puantaj → Fazla mesai → Tahakkuk
  → SSK matrahı → Brüt/Net ücret → Bordro
```

---

## ÇözBİM Stok Kodlama Yapısı
```
150.XX = İlk Madde ve Malzeme
  150.01 = Et ürünleri
  150.02 = Tahıllar (Pirinç, Bulgur)
  150.04 = Yağlar, Salçalar
  150.09 = Sebze/Meyve
  150.10 = Tek kullanımlık
  150.11 = Sarf malzeme
  150.13 = Temizlik

152.XX = Mamul / Yarı Mamul
  152.01 = Hazır yemekler (Çorbalar)

998.XX = Hizmet kalemleri
  998.10 = NORMAL YEMEK (satış kalemi)
```

## ÇözBİM Mönü Türleri
| Kod | Açıklama | Zaman |
|-----|----------|-------|
| NYemek_4 | Normal 4 çeşit | Öğle |
| Mesai | Mesai yemeği | Öğle/Akşam |
| GeceY | Gece yemeği | Gece |
| Kum_11Ç | Kumanya 11 çeşit | Çeşitli |
| Kah_5Ç | Kahvaltı 5 çeşit | Sabah |
| Ekmek | Ekmek servisi | - |
| Salata | Sadece salata | - |
| İFTARY | İftar yemeği | Akşam (Ramazan) |
