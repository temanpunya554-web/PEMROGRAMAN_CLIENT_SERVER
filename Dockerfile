FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM php:8.2-cli

RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html/
COPY --from=dependencies /app/vendor/ /var/www/html/vendor/

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080"]
