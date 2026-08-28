#!/bin/bash
# fable-094 (Ömer, 28 Ağu): "Excel indir deyince sayfa açılıyor, indirmiyor — direkt paylaş mı
# koysan (GTO'daki belge üretince paylaş mantığı gibi)." iOS uygulamasındaki WebView dosya
# indirmiyor; belge WhatsApp'tan gönderiliyor.
#
# Kokpit (187.124.181.84) ile OFUclaw (31.97.45.60) AYRI sunucularda ve OFUclaw'ın gateway'i
# yalnız localhost'ta dinliyor → uygulama doğrudan gönderemez. Akış:
#   Kokpit uygulaması  -> /root/uysatakip/gonder/<damga>.xlsx + .json   (konteyner mount'u)
#   bu script (host)   -> scp OFUclaw:/docker/openclaw-lhto/data/gonder -> openclaw message send
#
# HOST'ta çalışır (konteynerde ssh yok). Cron: her dakika.
# Kill-switch: /root/uysatakip/gonder/.kapali dosyası.
set -u
DIZIN="/root/uysatakip/gonder"
OFU="root@31.97.45.60"
OFU_DIZIN="/docker/openclaw-lhto/data/gonder"
KONTEYNER="openclaw-lhto-openclaw-1"
LOG="/var/log/uysatakip-wa-gonder.log"

[ -f "$DIZIN/.kapali" ] && exit 0
[ -d "$DIZIN" ] || exit 0

yaz() { echo "$(date '+%F %T') $*" >> "$LOG"; }

shopt -s nullglob
for meta in "$DIZIN"/*.json; do
  ad="$(basename "$meta" .json)"
  dosya="$DIZIN/$ad.xlsx"
  [ -f "$dosya" ] || { yaz "ATLANDI $ad: xlsx yok"; rm -f "$meta"; continue; }

  hedef="$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['hedef'])" "$meta" 2>/dev/null)"
  mesaj="$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['mesaj'])" "$meta" 2>/dev/null)"
  gonderilecekAd="$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['ad'])" "$meta" 2>/dev/null)"
  if [ -z "${hedef:-}" ]; then
    yaz "ATLANDI $ad: hedef okunamadi"
    mv -f "$meta" "$meta.hata" 2>/dev/null
    continue
  fi

  # Dosya adı ASCII olmalı: TR karakter WhatsApp/Content-Disposition sınırında bozulur.
  gonderilecekAd="$(echo "$gonderilecekAd" | tr -cd 'A-Za-z0-9._-')"
  [ -z "$gonderilecekAd" ] && gonderilecekAd="sayim.xlsx"

  if ! scp -o BatchMode=yes -o ConnectTimeout=20 -q "$dosya" "$OFU:/tmp/$gonderilecekAd"; then
    yaz "HATA $ad: scp basarisiz — sonraki turda tekrar denenecek"
    continue
  fi
  # /data/gonder mount'lu klasör (konteynerin /tmp'sine docker cp ÇALIŞMAZ — dosya kaybolur).
  if ! ssh -o BatchMode=yes -o ConnectTimeout=20 "$OFU" \
      "mkdir -p $OFU_DIZIN && mv -f /tmp/$gonderilecekAd $OFU_DIZIN/$gonderilecekAd"; then
    yaz "HATA $ad: uzak tasima basarisiz"
    continue
  fi

  cikti="$(ssh -o BatchMode=yes -o ConnectTimeout=60 "$OFU" \
    "docker exec $KONTEYNER openclaw message send --channel whatsapp --target '$hedef' \
     --media $OFU_DIZIN/$gonderilecekAd --force-document -m \"$mesaj\" --json" 2>&1)"
  if echo "$cikti" | grep -q '"messageId"'; then
    mid="$(echo "$cikti" | grep -oP '"messageId":\s*"\K[^"]+' | head -1)"
    yaz "GONDERILDI $ad -> $hedef (messageId=$mid)"
    rm -f "$dosya" "$meta"
  else
    yaz "HATA $ad: gonderim basarisiz -> $(echo "$cikti" | tail -2 | tr '\n' ' ')"
    mv -f "$meta" "$meta.hata" 2>/dev/null
    rm -f "$dosya"
  fi
done

# 7 günden eski hata dosyalarını temizle (kuyruk şişmesin)
find "$DIZIN" -name '*.hata' -mtime +7 -delete 2>/dev/null
exit 0
