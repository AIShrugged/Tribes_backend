FROM php:8.3-fpm

# Установка системных пакетов
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libpq-dev \
    libzip-dev \
    zip \
    gnupg2 \
    ca-certificates \
    nodejs \
    npm

# Установка PHP-расширений
RUN docker-php-ext-install pdo pdo_pgsql bcmath zip

# Установка Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Установка Node.js и npm (или Yarn, если нужно)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs

WORKDIR /var/www
