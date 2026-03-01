FROM php:8.2-apache

# PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# mod_rewrite  
RUN a2enmod rewrite headers

# PHP config
RUN echo "upload_max_filesize=30M\npost_max_size=32M\nmemory_limit=256M" \
    > /usr/local/etc/php/conf.d/uysa.ini

# Apache: PORT env var'ı dinle (Railway için kritik)
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# App
WORKDIR /var/www/html
COPY public/ .
RUN mkdir -p uploads && chmod 777 uploads \
    && chown -R www-data:www-data .

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
