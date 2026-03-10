# Use an official PHP image with Apache
FROM php:8.2-apache

# Install the PostgreSQL development libraries and drivers
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Copy your project files into the web server directory
COPY . /var/www/html/

# Ensure Apache is listening on the port Render provides
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Enable Apache mod_rewrite for your router logic
RUN a2enmod rewrite

# Start Apache in the foreground
CMD ["apache2-foreground"]