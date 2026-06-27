FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite

COPY . /var/www/html/

EXPOSE 80
CMD ["apache2-foreground"]