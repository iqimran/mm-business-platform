# Local development image for the Laravel API (PHP-FPM).
FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl

COPY php.ini /usr/local/etc/php/conf.d/zz-local.ini

WORKDIR /var/www/html
