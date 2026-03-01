# UYSA ERP v3.0 — PHP 8.2 + Apache (Railway Optimized)
FROM php:8.2-apache

# PHP uzantıları
RUN docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite
RUN a2enmod rewrite

# PHP upload ayarları
RUN { \
    echo 'upload_max_filesize = 30M'; \
    echo 'post_max_size = 32M'; \
    echo 'memory_limit = 256M'; \
    echo 'max_execution_time = 120'; \
} > /usr/local/etc/php/conf.d/uysa.ini

# Apache AllowOverride
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Uygulama dosyaları
WORKDIR /var/www/html
COPY public/ /var/www/html/

# Uploads klasörü
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html/uploads

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/health.php || exit 1

CMD ["apache2-foreground"]
