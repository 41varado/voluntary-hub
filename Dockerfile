# Entorno de desarrollo local: PHP 8.3 + Apache con las extensiones mongodb y redis.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libssl-dev pkg-config \
 && pecl install mongodb redis \
 && docker-php-ext-enable mongodb redis \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

# La raíz del sitio es public/ y se permiten los .htaccess
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/voluntacr.ini

WORKDIR /var/www/html
