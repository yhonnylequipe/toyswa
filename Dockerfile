FROM php:8.2-apache

# Instalar extensiones PDO MySQL y certificados SSL para TiDB Cloud
RUN apt-get update && apt-get install -y ca-certificates libpng-dev libwebp-dev libjpeg-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

# Crear carpeta uploads y dar permisos de escritura a Apache
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
