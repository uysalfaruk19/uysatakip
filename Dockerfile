FROM php:8.2-apache

# PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# mod_rewrite
RUN a2enmod rewrite

# PHP config
RUN echo "upload_max_filesize=30M\npost_max_size=32M\nmemory_limit=256M" \
    > /usr/local/etc/php/conf.d/uysa.ini

# AllowOverride All
RUN echo '<Directory /var/www/html>\n    AllowOverride All\n    Options -Indexes\n</Directory>' \
    >> /etc/apache2/apache2.conf

# App
WORKDIR /var/www/html
COPY public/ .

RUN mkdir -p uploads && chmod 777 uploads \
    && chown -R www-data:www-data .

EXPOSE 80
CMD ["apache2-foreground"]
