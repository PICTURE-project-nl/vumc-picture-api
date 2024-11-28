#!/bin/bash
DB_HOST=${DB_HOST}
DB_CONNECTION=${DB_CONNECTION}
DB_DATABASE=${MYSQL_DATABASE}
DB_USERNAME=${MYSQL_USER}
DB_PASSWORD=${MYSQL_PASSWORD}
export SERVER_NAME=localhost 


echo "DUMP IN entrypoint.sh inside laravel"
echo "DB_HOST=${DB_HOST}"
echo "DB_DATABASE=${DB_DATABASE}"
echo "DB_USERNAME=${DB_USERNAME}"
echo "DB_PASSWORD=${DB_PASSWORD}"

sed -i "s/LARAVEL_PROJECT_NAME/${LARAVEL_PROJECT_NAME}/g" /etc/apache2/sites-available/000-default.conf
sed -i "s/SERVER_NAME/${SERVER_NAME}/g" /etc/apache2/sites-available/000-default.conf

apache2ctl graceful

cd /var/www/laravel

if [ ! -d "${LARAVEL_PROJECT_NAME}" ]; then

  composer create-project --prefer-dist laravel/laravel ${LARAVEL_PROJECT_NAME}
  cd ${LARAVEL_PROJECT_NAME}

  composer require laravel/passport
  php artisan vendor:publish  --provider="KeycloakGuard\KeycloakGuardServiceProvider"

  sed -i "/DB_HOST/c\DB_HOST=${DB_HOST}" .env
  sed -i "/DB_CONNECTION/c\DB_CONNECTION=${DB_CONNECTION}" .env  
  sed -i "/DB_DATABASE/c\DB_DATABASE=${DB_DATABASE}" .env 
  sed -i "/DB_USERNAME/c\DB_USERNAME=${DB_USERNAME}" .env
  sed -i "/DB_PASSWORD/c\DB_PASSWORD=${DB_PASSWORD}" .env

  rm /config/app.php
  cp /config/app.php ./config/app.php
  cp /config/.gitignore ./.gitignore
  mkdir -p ./storage/app/public/nifti
  mkdir -p ./storage/app/public/l
  mkdir -p ./storage/app/public/h
  chown -R www-data:www-data storage
  php artisan migrate --force
  php artisan passport:install
  composer dump-autoload
  composer require "darkaonline/l5-swagger:5.7.*"
  composer require 'zircote/swagger-php:2.*'
  composer require webpatser/laravel-uuid
  php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
  php artisan storage:link
fi

if [ ! -f "${LARAVEL_PROJECT_NAME}/.env" ]; then
  cd ${LARAVEL_PROJECT_NAME}
  cp .env.example .env
  sed -i "/APP_URL/c\APP_URL=${SERVER_NAME}" .env

  php artisan key:generate

  if [ "${APP_ENV}" == 'local' ]; then
    sed -i "/APP_ENV/c\APP_ENV=local" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=true" .env
    sed -i "/APP_LOG_LEVEL/c\APP_LOG_LEVEL=debug" .env
    sed -i '/"host":/ s/"host":[^,]*/"host":"tool.${SERVER_HOSTNAME}"/' ./storage/api-docs/swagger.json
    sed -i '/host:/ s/host:[^,]*/host:"tool.${SERVER_HOSTNAME}"/' ./storage/api-docs/swagger.yaml

  elif [ "${APP_ENV}" == 'dev' ]; then
    sed -i "/APP_ENV/c\APP_ENV=staging" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=true" .env
    sed -i "/APP_LOG_LEVEL/c\APP_LOG_LEVEL=debug" .sev.activecolectivedevvm-61408.picture-quantivision.surf-hosted.nl"/' ./storage/api-docs/swagger.json
    sed -i '/host:/ s/host:[^,]*/host:"tool-dev.activecolectivedevvm-61408.picture-quantivision.surf-hosted.nl"/' ./storage/api-docs/swagger.yaml

  elif [ "${APP_ENV}" == 'prod' ]; then
    sed -i "/APP_ENV/c\APP_ENV=production" .env
    sed -i "/APP_DEBUG/c\APP_DEBUG=false" .env
    sed -i "/APP_LOG_LEVEL/c\APP_LOG_LEVEL=info" .env
    sed -i '/"host":/ s/"host":[^,]*/"host":"tool.activecolectivedevvm-61408.picture-quantivision.surf-hosted.nl"/' ./storage/api-docs/swagger.json
    sed -i '/host:/ s/host:[^,]*/host:"tool.activecolectivedevvm-61408.picture-quantivision.surf-hosted.nl"/' ./storage/api-docs/swagger.yaml
  fi

  sed -i "/DB_HOST/c\DB_HOST=${DB_HOST}" .env
  sed -i "/DB_CONNECTION/c\DB_CONNECTION=${DB_CONNECTION}" .env
  sed -i "/DB_DATABASE/c\DB_DATABASE=${DB_DATABASE}" .env
  sed -i "/DB_USERNAME/c\DB_USERNAME=${DB_USERNAME}" .env
  sed -i "/DB_PASSWORD/c\DB_PASSWORD=${DB_PASSWORD}" .env

  echo "" >> .env
  php artisan storage:link
fi

apache2ctl graceful
/usr/bin/supervisord -c /etc/supervisord.conf
supervisorctl -c /etc/supervisord.conf start laravel-worker:*
cd /var/www/laravel/vumc-picture-api/storage
mkdir -p framework/{sessions,views,cache}
chmod -R 777 framework
chown -R www-data:www-data framework
php artisan storage:link
mkdir -p app/dicom-unprocessed
chmod -R 777 app
chown -R www-data:www-data app
mkdir -p logs
chmod -R 777 logs
chown -R www-data:www-data logs
mkdir -p app/public/{nifti,l,h}
chmod -R 777 app/public
chown -R www-data:www-data app/public

cd ..
php artisan migrate
php artisan passport:install

tail -f /var/www/laravel/vumc-picture-api/storage/logs/laravel.log -f /var/log/cron.log -f /var/log/laravel-worker.log
