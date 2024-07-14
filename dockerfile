FROM php:8.3.9-apache
COPY index.php /var/www/html/
COPY vendor/ /var/www/html/vendor/
