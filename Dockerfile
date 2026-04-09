# Pin OS for reproducible Apache module layout (Bookworm)
FROM php:8.2-apache-bookworm

# Install MySQLi extension
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# mod_php requires mpm_prefork only. Remove duplicate MPM symlinks (build-time guard).
# Entrypoint repeats this at runtime for hosts where build cache skipped the fix.
RUN set -eux; \
    a2dismod mpm_event 2>/dev/null || true; \
    a2dismod mpm_worker 2>/dev/null || true; \
    rm -f /etc/apache2/mods-enabled/mpm_event.load \
          /etc/apache2/mods-enabled/mpm_event.conf \
          /etc/apache2/mods-enabled/mpm_worker.load \
          /etc/apache2/mods-enabled/mpm_worker.conf 2>/dev/null || true; \
    a2enmod mpm_prefork; \
    ls -la /etc/apache2/mods-enabled/mpm_* || true

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
