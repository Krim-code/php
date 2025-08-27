FROM php:8.2-apache

# Установка расширений (если надо zip, curl и прочее)
RUN apt-get update && apt-get install -y \
    zip unzip curl git && \
    docker-php-ext-install mysqli

# Включаем mod_rewrite
RUN a2enmod rewrite

# Настройка DocumentRoot 
WORKDIR /var/www/html
