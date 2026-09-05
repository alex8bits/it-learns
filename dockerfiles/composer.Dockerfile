FROM composer:latest

WORKDIR /var/www/laravel

ENTRYPOINT ["composer"]
