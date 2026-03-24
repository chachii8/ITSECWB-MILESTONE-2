#!/bin/bash
set -e

# Render expects apps to listen on PORT (default 10000)
PORT="${PORT:-80}"

# Configure Apache to listen on PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
