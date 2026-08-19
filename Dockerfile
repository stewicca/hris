# Stage 1: Build frontend assets (with PHP for Wayfinder)
FROM node:22-alpine AS frontend

WORKDIR /app

# Install PHP for Wayfinder code generation during build
RUN apk add --no-cache php84 php84-phar php84-mbstring php84-openssl \
    php84-tokenizer php84-xml php84-xmlwriter php84-dom php84-fileinfo \
    php84-pdo php84-pdo_sqlite php84-sqlite3 php84-ctype php84-session \
    php84-pcntl php84-posix php84-simplexml php84-xmlreader php84-iconv \
    && ln -sf /usr/bin/php84 /usr/local/bin/php

COPY composer.json composer.lock ./
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG VITE_APP_NAME=HRIS
ENV VITE_APP_NAME=${VITE_APP_NAME}

RUN npm run build

# Stage 2: PHP production image
FROM php:8.4-fpm-alpine AS app

WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libxml2-dev \
    oniguruma-dev \
    zip \
    unzip \
    git \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    opcache

# Install Redis extension
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Copy built frontend assets from previous stage
COPY --from=frontend /app/public/build ./public/build

# Install PHP dependencies (production only)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create required directories
RUN mkdir -p /var/log/supervisor

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy supervisor config
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

# Copy PHP-FPM config
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Copy PHP config
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

EXPOSE 9000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]

# Stage 3: Nginx with static assets baked in
FROM nginx:1.27-alpine AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/public /var/www/html/public
COPY --from=app /var/www/html/public/build /var/www/html/public/build
