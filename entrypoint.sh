#!/bin/bash
set -e

export PORT=${PORT:-8080}

echo "Starting entrypoint, PORT=$PORT"

if [ -d /var/www/html/var ]; then
  chmod -R 777 /var/www/html/var || true
  chown -R www-data:www-data /var/www/html/var || true
fi

mkdir -p /var/www/html/public/uploads/images
chmod -R 775 /var/www/html/public/uploads || true
chown -R www-data:www-data /var/www/html/public/uploads || true

echo "Starting PHP-FPM..."
php-fpm -D
sleep 2

if [ -f bin/console ]; then
  echo "JWT keys (if missing)..."
  php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction --env=prod || true

  echo "Installing assets..."
  php bin/console assets:install public --env=prod --no-debug || true

  echo "Clearing cache..."
  php bin/console cache:clear --env=prod --no-debug || true

  echo "Running database migrations..."
  if ! php bin/console doctrine:migrations:migrate --no-interaction --env=prod; then
    echo "WARNING: database migrations failed (cart may not work until cart_line table exists)."
  fi
fi

if [ -f /etc/nginx/conf.d/default.conf.template ]; then
  echo "Configuring Nginx port ($PORT)..."
  envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf
fi

echo "Starting Nginx..."
exec nginx -g "daemon off;"
