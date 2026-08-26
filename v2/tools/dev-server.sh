#!/usr/bin/env bash
# Lokal geliştirme sunucusu (WSL). SQLite; .env'e DOKUNMAZ (gerçek env > .env).
set -euo pipefail
cd "$(dirname "$0")/.."
export DB_DRIVER=sqlite
export DB_NAME=/tmp/uysa-dev.sqlite
export ADMIN_USER=uysal
export ADMIN_PASS=dev12345
export API_TOKEN=dev-token
export TRUST_PROXY=0
case "${1:-serve}" in
  init)
    rm -f "$DB_NAME"
    php tools/setup_db.php
    ;;
  seed-demo)
    php tools/dev-seed.php
    ;;
  serve)
    exec php -S 0.0.0.0:8099 -t public router.php >/tmp/uysa-dev.log 2>&1
    ;;
esac
