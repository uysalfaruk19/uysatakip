---
name: uysa-uretim-gir
description: UYSA catering günlük üretim girişi — Ömer "cantaş 450 opak 280" yazınca
  müşteri×kişi ayrıştırır, UYSA Kokpit API'sine (POST /api/uretim) işler, onay + eksik
  müşteri listesi döner. Fuzzy ad eşleşme (Türkçe karaktersiz/yanlış yazım toleranslı).
metadata:
  node_type: skill
  type: action
---

# UYSA Üretim Girişi (skill)

## Ne zaman tetiklenir
Ömer/Azim şu kalıplardan birini yazınca (Telegram/WhatsApp):
- "cantaş 450 opak 280"  (müşteri adı + kişi, çoklu)
- "bugün talay 45 ermetal 23"
- "cantas 450"  (tek müşteri)
- "opak 280 15 temmuz"  → tarih belirtilirse o güne (belirtilmezse BUGÜN)

Sadece açık "üretim/kişi girişi" niyeti varsa çalıştır. Fatura/gider farklı skill.

## Nasıl çalışır
1. Metinden (müşteri adı, kişi sayısı) çiftlerini çıkar. Çok kelimeli ad + rakam kalıbı:
   rakam görene kadar kelimeler ad, rakam o adın kişi sayısı.
2. Tarih verilmediyse bugünün tarihini kullan (YYYY-MM-DD).
3. Aşağıdaki curl'ü çağır (API fuzzy eşleşmeyi kendi yapar — ham metni `text` olarak gönder,
   ayrıştırmayı da API yapabilir; en sağlamı ham metni yollamak):

```
curl -s -X POST "$UYSA_API/api/uretim" \
  -H "X-UYSA-Token: $UYSA_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text":"<HAM MESAJ>","date":"<YYYY-MM-DD ops.>"}'
```

Env (köprü host'unda):
- `UYSA_API`   = https://uysatakip.uysa019.cloud   (v2 köke deploy edilince)
- `UYSA_TOKEN` = .env'deki API_TOKEN (secrets; koda gömme)

## Yanıtı Ömer'e nasıl özetle
API JSON döner:
```json
{
 "ok": true, "tarih": "2026-07-03", "ogun": "ogle",
 "kayitlar": [
   {"musteri":"CANTAŞ","kisi":450,"birim_fiyat":328,"tutar":147600,"eslesme":1.0,"yontem":"tam"},
   {"musteri":"OPAK","kisi":280,"birim_fiyat":250,"tutar":70000,"eslesme":1.0,"yontem":"tam"}
 ],
 "eslesmeyen": [],
 "gun_toplam": {"kisi":730,"tutar":217600},
 "eksik": ["BOMİ","ERMETAL","CEOTHERM","E-DEPO","PENDORYA","TALAY LOJİSTİK"]
}
```
Ömer'e KISA cevap ver (tek/iki satır, laf dolandırma):
> ✅ 03.07 kaydedildi: Cantaş 450 (147.600₺), Opak 280 (70.000₺). Gün: 730 kişi / 217.600₺.
> Eksik: Bomi, Ermetal, Ceotherm, E-Depo, Pendorya, Talay.

Kurallar:
- `eslesmeyen` doluysa: "❓ '<girdi>' eşleşmedi — tam adı yaz" de, TAHMİN ETME.
- `ok:false` → hata mesajını aynen ilet, uydurma.
- Para formatı Türkçe: 147.600 (nokta binlik). Emoji minimum (sadece ✅/❓).
- Bu skill sadece YAZAR; sorgu/özet için `GET /api/ozet?gun=` veya `/api/rapor?ay=` kullan.

## İlgili endpoint'ler (okuma)
- `GET /api/ozet?gun=YYYY-MM-DD` → o günün müşteri kırılımı + eksikler
- `GET /api/ozet?ay=YYYY-MM` → ay üretim + finans özeti
- `GET /api/rapor?ay=YYYY-MM` → müşteri×ay (kişi, ciro, gün) — akşam karnesi için
