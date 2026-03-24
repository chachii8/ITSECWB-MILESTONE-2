FROM php:8.2-apache

# Install MySQLi extension
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application files
COPY . /var/www/html/

# Set permissions for uploads
RUN mkdir -p /var/www/html/images && chmod -R 777 /var/www/html/images
RUN mkdir -p /var/www/html/images/profile_photos && chmod -R 777 /var/www/html/images/profile_photos

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Entrypoint for Render: listen on PORT (default 10000)
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

EXPOSE 80
