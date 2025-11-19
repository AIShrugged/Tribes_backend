FROM third-party-registry.fabit.ru/docker.io/library/php:8.3-fpm-alpine3.21

# Установка системных пакетов
RUN apk update && apk --no-cache add \
    git=2.47.3-r0 \
    unzip=6.0-r15 \
    curl=8.14.1-r2 \
    libpq-dev=17.7-r0 \
    libzip-dev=1.11.2-r0 \
    zip=3.0-r13 \
    gnupg=2.4.7-r0 \
    ca-certificates=20250911-r0 \
    nodejs=22.15.1-r0 \
    npm=10.9.1-r0

# Установка PHP-расширений
RUN docker-php-ext-install pdo pdo_pgsql bcmath zip

# Установка Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www
WORKDIR /var/www
RUN /usr/bin/composer install
RUN npm install

ENTRYPOINT ["/usr/local/bin/docker-php-entrypoint"]