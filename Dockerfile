FROM php:8.3-fpm

WORKDIR /var/www/html

# System deps + Node (Webpack Encore) + Nginx
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    nginx \
    gettext-base \
    nodejs \
    npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
    intl \
    xml \
    pdo \
    pdo_mysql \
    mbstring \
    opcache \
    gd

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

COPY nginx.conf /etc/nginx/conf.d/default.conf.template
COPY nginx-main.conf /etc/nginx/nginx.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Install PHP deps first (better layer cache)
COPY composer.json composer.lock symfony.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# Frontend assets (AgriNest uses Webpack Encore, not AssetMapper/importmap)
RUN npm run build

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log var/sessions public/build \
    && cp -a public/uploads /opt/uploads-seed \
    && chmod -R 777 var/ \
    && chown -R www-data:www-data var/ || true

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
