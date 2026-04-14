FROM docker.io/library/php:8.3-cli-alpine3.21
LABEL keep="true"

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    curl \
    ca-certificates \
    make \
    gcc \
    g++ \
    musl-dev \
    linux-headers \
    nodejs \
    npm \
    python3 \
    py3-pip \
    postgresql-dev \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo pdo_pgsql bcmath zip \
    && docker-php-ext-install pdo_mysql \
    && pip3 install --no-cache-dir --break-system-packages requests==2.32.3

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace
COPY . /opt/sandbox_runner/

ENTRYPOINT ["python3", "/opt/sandbox_runner/runner.py"]
