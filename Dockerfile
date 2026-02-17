FROM third-party-registry.fabit.ru/docker.io/library/php:8.3-fpm-alpine3.21

# Установка системных пакетов
RUN apk update && apk --no-cache add \
    nginx \
    git \
    unzip \
    curl \
    postgresql-dev \
    libzip-dev \
    zip \
    gnupg \
    ca-certificates \
    nodejs \
    npm

# Установка PHP-расширений
RUN docker-php-ext-install pdo pdo_pgsql bcmath zip

# Установка Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chmod=755 ./docker-php-entrypoint /var/www/docker-php-entrypoint
COPY --chown=www-data:www-data . /var/www
#COPY .env.example /var/www/.env
WORKDIR /var/www
RUN /usr/bin/composer install
#RUN mkdir /var/run/php
RUN npm install

EXPOSE 9000

ENTRYPOINT ["/var/www/docker-php-entrypoint"]
CMD [""]