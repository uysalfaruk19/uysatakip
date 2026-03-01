FROM php:8.2-cli

# PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# PHP ayarları
RUN printf "upload_max_filesize=30M\npost_max_size=32M\nmemory_limit=256M\nmax_execution_time=120\n" \
    > /usr/local/etc/php/conf.d/uysa.ini

# Dosyaları kopyala
WORKDIR /var/www/html
COPY public/ .

# Uploads klasörü
RUN mkdir -p uploads && chmod 777 uploads

EXPOSE 8080

# PHP built-in server (Apache MPM sorunu yok, Railway ile %100 uyumlu)
CMD php -S 0.0.0.0:${PORT:-8080} -t /var/www/html

