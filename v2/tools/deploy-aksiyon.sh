#!/usr/bin/env bash
# UYSA Kokpit — aksiyon deseni sürümü canlıya alma (tek çalıştırma).
#
# Ömer için: bu dosya VPS'e kopyalanıp ORADA çalıştırılır (inline SSH komutu YOK).
#   scp v2/tools/deploy-aksiyon.sh root@187.124.181.84:/root/
#   ssh root@187.124.181.84 'bash /root/deploy-aksiyon.sh'
#
# Sıra: rollback yedeği → kod → imaj → ayağa kaldır → duman testi.
# Herhangi bir adım patlarsa script DURUR (set -e) ve geri dönüş yolu hazırdır.
set -euo pipefail

REPO="${REPO:-/root/uysatakip}"
COMPOSE="${COMPOSE:-docker-compose.v2.yml}"
SERVIS="${SERVIS:-app-v2}"           # compose'daki servis adı
KONTEYNER="${KONTEYNER:-uysatakip-v2}"
DB_KONTEYNER="${DB_KONTEYNER:-uysatakip-db}"
DAL="${DAL:-tasarim-aksiyon}"
DAMGA="$(date +%Y%m%d-%H%M)"
YEDEK_DIZIN="/root/kokpit-rollback"

echo "=== 0) Ön kontrol ==================================================="
cd "$REPO"
git rev-parse --abbrev-ref HEAD
docker compose -f "$COMPOSE" ps || docker-compose -f "$COMPOSE" ps

echo
echo "=== 1) ROLLBACK YEDEĞİ (önce geri dönüş yolu) ======================="
mkdir -p "$YEDEK_DIZIN"
# 1a. Çalışan imajı etiketle — geri dönüş tek komut olsun
ESKI_IMAJ="$(docker inspect "$KONTEYNER" --format '{{.Image}}' 2>/dev/null || true)"
if [ -n "$ESKI_IMAJ" ]; then
  docker tag "$ESKI_IMAJ" "kokpit-rollback:$DAMGA"
  echo "  imaj etiketlendi → kokpit-rollback:$DAMGA"
else
  echo "  DURDU: çalışan imaj bulunamadı, geri dönüş yolu olmadan devam edilmez"
  exit 1
fi
# 1b. Veritabanı dökümü — DB ayrı konteynerde (mariadb), dump oradan alınır.
set +u
# shellcheck disable=SC1091
source .env.v2 2>/dev/null || true
set -u
DUMP="$YEDEK_DIZIN/uysa_v2-$DAMGA.sql.gz"
docker exec "$DB_KONTEYNER" mariadb-dump \
  -u "${DB_USER:-uysa}" -p"${DB_PASS:-}" "${DB_NAME:-uysa_v2}" 2>/dev/null \
  | gzip > "$DUMP" \
  || docker exec "$DB_KONTEYNER" mysqldump \
       -u "${DB_USER:-uysa}" -p"${DB_PASS:-}" "${DB_NAME:-uysa_v2}" | gzip > "$DUMP"
# Boş/bozuk dump ile devam edilmez — geri dönüş yolu gerçekten var olmalı.
BOYUT="$(stat -c %s "$DUMP")"
if [ "$BOYUT" -lt 10000 ]; then
  echo "  DURDU: DB dökümü çok küçük ($BOYUT byte) — yedek güvenilir değil"
  exit 1
fi
ls -lh "$DUMP"
echo "  DB dökümü alındı ($BOYUT byte)."

echo
echo "=== 2) KOD ========================================================="
git fetch --all --prune
git checkout "$DAL"
git pull --ff-only origin "$DAL"
git log --oneline -1

echo
echo "=== 3) İMAJ + AYAĞA KALDIR ========================================="
docker build -f Dockerfile.v2 -t "uysatakip-v2:$DAMGA" .
docker compose -f "$COMPOSE" up -d --build
sleep 6
docker compose -f "$COMPOSE" ps

echo
echo "=== 4) DUMAN TESTİ ================================================="
HATA=0
kontrol() {  # kontrol <ad> <url> <beklenen-http>
  local ad="$1" url="$2" bek="$3" kod
  kod="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: uysatakip.uysa019.cloud' "$url" || echo 000)"
  if [ "$kod" = "$bek" ]; then
    echo "  ✓ $ad ($kod)"
  else
    echo "  ✗ $ad → $kod (beklenen $bek)"
    HATA=1
  fi
}
kontrol "health"        "http://127.0.0.1:8093/health.php"   200
kontrol "login"         "http://127.0.0.1:8093/login.php"    200
kontrol "bugun (giriş yoksa yönlendirir)" "http://127.0.0.1:8093/bugun.php" 302
kontrol "gelen (yeni ekran)" "http://127.0.0.1:8093/gelen.php" 302
kontrol "yakinda (KALDIRILDI, 404 bekleniyor)" "http://127.0.0.1:8093/yakinda.php" 404

echo
if [ "$HATA" -eq 0 ]; then
  echo "=== SONUÇ: DEPLOY TAMAM ==========================================="
  echo "Rollback gerekirse:"
  echo "  docker tag kokpit-rollback:$DAMGA uysatakip-v2:latest && docker compose -f $COMPOSE up -d"
  echo "  zcat $DUMP | mysql -u ${DB_USER:-root} ${DB_NAME:-uysa_v2}   # yalnız veri bozulduysa"
else
  echo "=== SONUÇ: DUMAN TESTİ KIRMIZI — GERİ AL =========================="
  echo "  docker tag kokpit-rollback:$DAMGA uysatakip-v2:latest && docker compose -f $COMPOSE up -d"
  exit 1
fi
