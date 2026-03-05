FROM php:8.2-apache

# Включаем mod_rewrite для красивых ссылок
RUN a2enmod rewrite

# Устанавливаем PDO MySQL для подключения к базе
RUN docker-php-ext-install pdo pdo_mysql

# Устанавливаем рабочую директорию
WORKDIR /var/www/html
