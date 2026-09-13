FROM php:8.3-fpm

WORKDIR /var/www/laravel

RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libonig-dev \
    libsqlite3-dev \
    unzip \
    zip \
    git \
    libzip-dev \
    libpq-dev \
    curl \
    libssl-dev \
    libxml2-dev \
    && apt-get clean

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite gd mbstring bcmath zip

# SQLite CLI tool — для отладки SQLite-БД из контейнера.
RUN apt-get update && apt-get install -y sqlite3 && apt-get clean

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

RUN mkdir -p /home/www-data && \
    chown -R www-data:www-data /home/www-data && \
    usermod -d /home/www-data www-data

RUN chown -R www-data:www-data /var/www/laravel

USER www-data
