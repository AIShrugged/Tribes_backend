FROM docker.io/library/php:8.3-fpm-alpine3.21

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
  git unzip curl docker-cli \
  postgresql-dev \
  libzip-dev zip \
  nodejs npm \
  ca-certificates

RUN install-php-extensions pdo_pgsql bcmath zip redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Composer deps (кешируется) — НО без artisan scripts
ARG INSTALL_DEV_DEPS=false

COPY composer.json composer.lock ./
RUN if [ "$INSTALL_DEV_DEPS" = "true" ]; then \
      COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts; \
    else \
      COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --no-scripts; \
    fi

# NPM deps (кешируется)
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# Копируем проект
COPY --chown=www-data:www-data . .

# Теперь можно выполнить package discovery (уже есть artisan)
RUN php artisan package:discover --ansi || true

# Сборка ассетов (если Vite)
RUN npm run build || true

COPY --chown=www-data:www-data .docker/php-fpm-www.conf /usr/local/etc/php-fpm.d/www.conf

# The storage/framework/* and storage/app/* runtime dirs are excluded from the
# build context by .dockerignore (avoids "permission denied" on 0700 subdirs and
# keeps runtime data out of the image). They must still EXIST in the image, or
# Laravel's view compiler gets an empty compiled path → "Please provide a valid
# cache path" 500 on every Blade render (incl. "/", which the nginx healthcheck hits).
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/framework/testing \
      storage/app/public \
      storage/app/private \
      storage/logs \
      bootstrap/cache \
  && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 ./docker-php-entrypoint /var/www/docker-php-entrypoint

EXPOSE 9000

ENTRYPOINT ["/var/www/docker-php-entrypoint"]
CMD ["backend"]
