FROM node:22-alpine AS frontend
WORKDIR /frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.4-fpm-alpine AS php-base
RUN apk add --no-cache icu-libs libzip && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers && docker-php-ext-install pdo_mysql pcntl intl zip opcache && pecl install redis-6.2.0 && docker-php-ext-enable redis && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
COPY backend/ ./
COPY docs/openapi.yaml ./resources/project-docs/openapi.yaml
COPY README.md ./resources/project-docs/README.md
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data storage/logs && composer dump-autoload --optimize --no-dev && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php.ini /usr/local/etc/php/conf.d/fluxo.ini
EXPOSE 9000
CMD ["php-fpm"]

FROM php-base AS testing
RUN cp .env.example .env && composer install --prefer-dist --no-interaction --no-progress
CMD ["php","artisan","test"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /frontend/dist /usr/share/nginx/html
EXPOSE 8080
