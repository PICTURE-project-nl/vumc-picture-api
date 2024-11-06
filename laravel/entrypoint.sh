#!/bin/bash

# Environment variables
DB_HOST=${DB_HOST}
DB_CONNECTION=${DB_CONNECTION}
DB_DATABASE=${MYSQL_DATABASE}
DB_USERNAME=${MYSQL_USER}
DB_PASSWORD=${MYSQL_PASSWORD}

echo "Entrypoint.sh credentials check:"
echo "DB_HOST=${DB_HOST}"
echo "DB_CONNECTION=${DB_CONNECTION}"
echo "DB_DATABASE=${DB_DATABASE}"
echo "DB_USERNAME=${DB_USERNAME}"

# Configure Apache
sed -i "s/LARAVEL_PROJECT_NAME/${LARAVEL_PROJECT_NAME}/g" /etc/apache2/sites-available/000-default.conf
sed -i "s/SERVER_NAME/${SERVER_NAME}/g" /etc/apache2/sites-available/000-default.conf
apache2ctl graceful

# Move to Laravel directory
cd /var/www/laravel

# Laravel Project Setup
if [ ! -d "${LARAVEL_PROJECT_NAME}" ]; then
  composer create-project --prefer-dist laravel/laravel ${LARAVEL_PROJECT_NAME}
  cd ${LARAVEL_PROJECT_NAME}

  # Laravel Passport and Keycloak configuration
  composer require laravel/passport
  php artisan vendor:publish  --provider="KeycloakGuard\KeycloakGuardServiceProvider"

  # Set .env configuration
  cp .env.example .env
  sed -i "/DB_HOST/c\DB_HOST=${DB_HOST}" .env
  sed -i "/DB_CONNECTION/c\DB_CONNECTION=${DB_CONNECTION}" .env
  sed -i "/DB_DATABASE/c\DB_DATABASE=${DB_DATABASE}" .env
  sed -i "/DB_USERNAME/c\DB_USERNAME=${DB_USERNAME}" .env
  sed -i "/DB_PASSWORD/c\DB_PASSWORD=${DB_PASSWORD}" .env
  php artisan key:generate

  # Configure Swagger, UUID, etc.
  composer require "darkaonline/l5-swagger:5.7.*" 'zircote/swagger-php:2.*' webpatser/laravel-uuid
  php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
  php artisan storage:link
fi

# Check environment settings and apply them
if [ -f "${LARAVEL_PROJECT_NAME}/.env" ]; then
  cd ${LARAVEL_PROJECT_NAME}

  if [ "${APP_ENV}" == 'local' ]; then
    sed -i "/APP_ENV/c\APP_ENV=local" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=true" .env
    sed -i "/APP_LOG_LEVEL/c\APP_LOG_LEVEL=debug" .env
  elif [ "${APP_ENV}" == 'dev' ]; then
    sed -i "/APP_ENV/c\APP_ENV=staging" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=true" .env
  elif [ "${APP_ENV}" == 'prod' ]; then
    sed -i "/APP_ENV/c\APP_ENV=production" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=false" .env
    sed -i "/APP_LOG_LEVEL/c\APP_LOG_LEVEL=info" .env
  fi
fi

# Manage permissions for storage directory
mkdir -p ./storage/framework/{sessions,views,cache} ./storage/app/{public/{nifti,l,h},dicom-unprocessed,logs}
chmod -R 777 storage
chown -R www-data:www-data storage

# Run migrations and Passport installation
echo "Running migrations..."
php artisan migrate --force || { echo "Migration failed"; exit 1; }
echo "Passport installation..."
php artisan passport:install || { echo "Passport installation failed"; exit 1; }

# Start Apache and Supervisor
apache2ctl -D FOREGROUND
/usr/bin/supervisord -c /etc/supervisord.conf
supervisorctl -c /etc/supervisord.conf start laravel-worker:*

# Monitor logs
tail -f /var/www/laravel/vumc-picture-api/storage/logs/laravel.log -f /var/log/cron.log -f /var/log/laravel-worker.log