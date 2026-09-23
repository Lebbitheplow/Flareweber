# Stage 1: prebuild the Worker bundle so publishing from this image needs no
# Node runtime (CloudflareWorkersProvider prefers worker/dist/worker.mjs).
FROM node:22-alpine AS worker-builder
WORKDIR /worker
COPY worker/package.json worker/package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev --no-audit --no-fund; \
    else npm install --omit=dev --no-audit --no-fund; fi
COPY worker/ ./
RUN npx --yes esbuild@0.25 src/index.ts --bundle --format=esm --platform=neutral \
    --outfile=dist/worker.mjs --minify --log-level=error

FROM php:8.3-apache

# Microweber 2.0.20 requires ext-intl and ext-sodium on top of the usual
# Laravel set; pdo_sqlite ships with the base image.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    libicu-dev libonig-dev libsodium-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl zip sodium pdo_mysql opcache \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Microweber 2.0.20 serves from the app root: index.php and userfiles/ live
# there (not under public/), and the root .htaccess needs AllowOverride All.
ENV APACHE_DOCUMENT_ROOT=/var/www/flareweber/app
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/flareweber/app>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/flareweber.conf \
    && printf '# Defence in depth: the root .htaccess already blocks these, but the\n# app dir is a user volume and may be edited.\n<FilesMatch "^\\.">\n    Require all denied\n</FilesMatch>\n<DirectoryMatch "^/var/www/flareweber/app/(storage|vendor|config|bootstrap|database|src|tests)(/|$)">\n    Require all denied\n</DirectoryMatch>\n' >> /etc/apache2/conf-available/flareweber.conf \
    && a2enconf flareweber

# Same limits as the desktop runtime: media uploads and long publishes.
RUN printf 'memory_limit = 1024M\nmax_execution_time = 0\nupload_max_filesize = 64M\npost_max_size = 64M\n' \
    > /usr/local/etc/php/conf.d/flareweber.ini

WORKDIR /var/www/flareweber

COPY . /var/www/flareweber/
COPY --from=worker-builder /worker/dist/worker.mjs /var/www/flareweber/worker/dist/worker.mjs

RUN chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["apache2-foreground"]
