#!/bin/bash
set -e

# Railway PORT env var varsa Apache'yi o portta dinlet
PORT="${PORT:-80}"
echo "Starting Apache on port $PORT..."

# Apache port konfigürasyonu
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/:80>/:$PORT>/" /etc/apache2/sites-enabled/000-default.conf

# AllowOverride All
cat >> /etc/apache2/apache2.conf << EOF

<Directory /var/www/html>
    AllowOverride All
    Options -Indexes +FollowSymLinks
    Require all granted
</Directory>
EOF

exec "$@"
