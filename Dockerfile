# UYSA ERP v3.0 — PHP 8.2 + Apache
FROM php:8.2-apache

# PHP uzantıları
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libzip-dev unzip curl \
    && docker-php-ext-install pdo pdo_mysql zip exif \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite
RUN a2enmod rewrite

# PHP ayarları
RUN echo "upload_max_filesize = 30M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 32M"    >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M"    >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/uploads.ini

# Apache konfigürasyonu — AllowOverride için
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Uygulama dosyaları
WORKDIR /var/www/html
COPY public/ /var/www/html/

# Uploads klasörü izinleri
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod 755 /var/www/html/uploads

# Genel izinler
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
