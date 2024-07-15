FROM php:8.3.9-apache

RUN apache2ctl -M | grep -q rewrite_module || a2enmod rewrite

COPY public/ /var/www/html/
COPY vendor/ /var/www/html/vendor/
