FROM php:8.2-apache

# Install MySQLi extension
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# PHP + mod_php requires mpm_prefork. Newer base images may enable mpm_event too,
# which causes: AH00534 "More than one MPM loaded" and crash loops.
RUN set -eux; \
    a2dismod mpm_event 2>/dev/null || true; \
    a2dismod mpm_worker 2>/dev/null || true; \
    a2enmod mpm_prefork

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
