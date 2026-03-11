FROM php:8.2-apache

ENV TEST_MODE=true

# Install PostgreSQL drivers
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Copy code to the root web directory
COPY . /var/www/html/

# REQUIRED: Enable mod_rewrite and allow .htaccess overrides
RUN a2enmod rewrite
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Adjust ports for Render
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE 80