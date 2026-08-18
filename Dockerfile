FROM node:24-alpine AS frontend

WORKDIR /app

ARG VITE_SENTRY_DSN=
ARG VITE_SENTRY_ENVIRONMENT=production
ARG VITE_SENTRY_RELEASE=
ENV VITE_SENTRY_DSN=$VITE_SENTRY_DSN \
    VITE_SENTRY_ENVIRONMENT=$VITE_SENTRY_ENVIRONMENT \
    VITE_SENTRY_RELEASE=$VITE_SENTRY_RELEASE

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json ./
RUN npm run build

FROM php:8.4-fpm-alpine AS application

WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        libwebp-dev \
        oniguruma-dev \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && rm -rf /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug=rwX storage bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage

COPY docker/php/entrypoint.sh /usr/local/bin/passion-entrypoint
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
RUN sed -i 's/^pm\.max_children = .*/pm.max_children = 8/' /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^pm.max_children = 8$' /usr/local/etc/php-fpm.d/www.conf
RUN chmod 755 /usr/local/bin/passion-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/passion-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=application /var/www/html/public /var/www/html/public

RUN mkdir -p /var/www/html/storage/app/public

EXPOSE 80
