FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
    ca-certificates \
    git \
    unzip \
    libssl-dev \
    libsasl2-dev \
    pkg-config \
 && pecl install mongodb-1.21.5 \
 && docker-php-ext-enable mongodb \
 && docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 80
