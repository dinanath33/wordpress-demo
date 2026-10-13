FROM wordpress:php8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip mysqli

RUN a2enmod rewrite

WORKDIR /var/www/html