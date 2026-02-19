FROM third-party-registry.fabit.ru/docker.io/library/php:8.3-fpm-alpine3.21

# Системные зависимости
RUN apk add --no-cache \
  git unzip curl \
  postgresql-dev \
  libzip-dev zip \
  nodejs npm \
  ca-certificates

# PHP extensions (стандартные)
RUN docker-php-ext-install pdo pdo_pgsql bcmath zip

# Установка PHP Redis (phpredis)
RUN apk add --no-cache $PHPIZE_DEPS \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# --- Composer deps (кешируется) ---
# Сначала копируем только composer.* чтобы работал кеш слоёв
COPY composer.json composer.lock ./
RUN php -v && composer -V && php -m \
  && COMPOSER_MEMORY_LIMIT=-1 composer install -vvv \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader

# --- NPM deps (кешируется) ---
# Также сначала копируем только package.* для кеша
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# --- Копируем проект ---
COPY --chown=www-data:www-data . .

# Сборка ассетов (если Vite)
RUN npm run build || true

# Права (Laravel)
RUN mkdir -p storage bootstrap/cache \
  && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 ./docker-php-entrypoint /var/www/docker-php-entrypoint

EXPOSE 9000

ENTRYPOINT ["/var/www/docker-php-entrypoint"]
CMD ["backend"]
