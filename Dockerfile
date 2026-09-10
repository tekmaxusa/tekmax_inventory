FROM php:8.2-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    APP_ENV=production \
    APP_BASE= \
    DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd zip opcache \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

# Production PHP defaults
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=10M'; \
      echo 'session.cookie_httponly=1'; \
      echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/inventory.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

# Writable runtime dirs
RUN mkdir -p /var/www/html/storage/logs /var/www/html/uploads/products \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads \
    && chmod -R 775 /var/www/html/storage /var/www/html/uploads

COPY docker/entrypoint.sh /usr/local/bin/inventory-entrypoint.sh
RUN chmod +x /usr/local/bin/inventory-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD curl -fsS http://127.0.0.1/login.php >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/inventory-entrypoint.sh"]
CMD ["apache2-foreground"]
