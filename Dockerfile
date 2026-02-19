FROM third-party-registry.fabit.ru/docker.io/library/php:8.3-fpm-alpine3.21

RUN apk add --no-cache \
  git unzip curl \
  postgresql-dev \
  libzip-dev zip \
  nodejs npm \
  ca-certificates

RUN docker-php-ext-install pdo pdo_pgsql bcmath zip

RUN apk add --no-cache $PHPIZE_DEPS \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Composer deps (кешируется) — НО без artisan scripts
COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# NPM deps (кешируется)
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# Копируем проект
COPY --chown=www-data:www-data . .

# Теперь можно выполнить package discovery (уже есть artisan)
RUN php artisan package:discover --ansi || true

# Сборка ассетов (если Vite)
RUN npm run build || true

RUN mkdir -p storage bootstrap/cache \
  && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 ./docker-php-entrypoint /var/www/docker-php-entrypoint

EXPOSE 9000

ENTRYPOINT ["/var/www/docker-php-entrypoint"]
CMD ["backend"]