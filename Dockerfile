# ============================================================================
#  Mirza Bot - Dockerfile for Coolify deployment
#  PHP 8.2 + Apache + all required extensions + cron (supervised)
# ============================================================================
FROM php:8.2-apache

# ---- System packages & build deps -----------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        cron \
        curl \
        unzip \
        git \
        supervisor \
        default-mysql-client \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libssh2-1-dev \
        libssh2-1 \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# ---- PHP extensions --------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo \
        pdo_mysql \
        gd \
        zip \
        mbstring \
        soap \
    && (pecl install ssh2 || pecl install ssh2-beta) \
    && docker-php-ext-enable ssh2

# ---- Apache config ---------------------------------------------------------
# Set ServerName globally to suppress the AH00558 "could not reliably determine
# the server's fully qualified domain name" warning on startup.
RUN a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Reasonable PHP runtime settings for a Telegram bot handling webhooks
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
        echo 'max_execution_time = 120'; \
        echo 'date.timezone = Asia/Tehran'; \
    } > /usr/local/etc/php/conf.d/mirza.ini

# ---- App source ------------------------------------------------------------
WORKDIR /var/www/html
COPY . /var/www/html

# Ownership for the web server
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ---- Container scripts -----------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/supervisord-main.conf /etc/supervisor/supervisord.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/mirza.conf
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/log/supervisor

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
