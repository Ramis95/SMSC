FROM php:7.3-fpm

ADD . /var/www/html
COPY . /var/www/html

RUN apt-get update \
&& docker-php-ext-install pdo pdo_mysql \
&& chown -R www-data:www-data /var/www/html \
&& chmod -R 777 /var/www/html



