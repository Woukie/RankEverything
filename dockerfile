FROM php:8.3.9-apache

RUN apache2ctl -M | grep -q rewrite_module || a2enmod rewrite
RUN docker-php-ext-install mysqli

COPY public/ /var/www/html/
COPY vendor/ /var/www/html/vendor/
