FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql

WORKDIR /app
COPY . /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app"]