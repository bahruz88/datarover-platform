FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libldap2-dev \
        libonig-dev \
        unzip \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mysqli \
        ldap \
        mbstring \
        zip

RUN a2enmod rewrite headers

# Apache serves /datarover at the URL prefix, matching the existing XAMPP layout
COPY ./ /var/www/html/datarover/
RUN chown -R www-data:www-data /var/www/html/datarover \
    && find /var/www/html/datarover -type d -exec chmod 755 {} \; \
    && find /var/www/html/datarover -type f -exec chmod 644 {} \;

# Default landing redirect → /datarover/
RUN printf '<?php header("Location: /datarover/"); exit;' > /var/www/html/index.php

EXPOSE 80
