#!/bin/bash
set -e

# Only one Apache MPM may be active. php:apache uses mod_php → mpm_prefork.
# Some hosts still leave mpm_event enabled → AH00534 crash loop. Fix at runtime (Railway/Render).
a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Render / Railway expect apps to listen on PORT (default 80 locally)
PORT="${PORT:-80}"

# Configure Apache to listen on PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
