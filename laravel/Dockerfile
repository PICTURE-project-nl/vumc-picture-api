FROM ubuntu:20.04

COPY SimpleITK-1.2.0-cp37-cp37m-manylinux1_x86_64.whl /tmp/
COPY SimpleITK-1.2.3-cp38-cp38-manylinux1_x86_64.whl /tmp/

RUN apt update
RUN apt -y install build-essential
RUN apt -y install gcc-8 g++-8 gcc-9 g++-9 gcc-10 g++-10

RUN update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-8 8
RUN update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-8 8
RUN update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-9 9
RUN update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-9 9
RUN update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-10 10
RUN update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-10 10

ENV TZ=Europe/Minsk
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN apt update && apt upgrade -y

RUN DEBIAN_FRONTEND=noninteractive && apt-get update && apt-get install -y tzdata
RUN apt-get -y purge php.*
RUN apt-get update && apt-get -y install software-properties-common && \
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php

# Get Ubuntu packages
RUN apt-get update && apt-get install -y git zip locales \
  supervisor make cmake zlib1g-dev \
  apache2 php8.2 libapache2-mod-php8.2 curl php8.2-curl \
  php8.2-mbstring php8.2-gettext php8.2-gd php8.2-zip \
  php8.2-cli php8.2-mysql php8.2-xml python3-tk \
  apt python3-apt dcm2niix python3-pip cron \
  libfreetype6-dev libpng-dev pkg-config libcairo2 libcairo2-dev ninja-build python3.8-dev libssl-dev \
  libtiff5-dev libjpeg8-dev libopenjp2-7-dev zlib1g-dev \
  libfreetype6-dev liblcms2-dev libwebp-dev tcl8.6-dev tk8.6-dev python3-tk \
  libharfbuzz-dev libfribidi-dev libxcb1-dev libgirepository1.0-dev python3-cairo-dev clamav

# Get new virus-definitions
RUN freshclam
# Set the locale
RUN sed -i -e 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' /etc/locale.gen && \
    locale-gen
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8

# Install Python packages
RUN git config --global url.https://github.com/.insteadOf git://github.com/
RUN pip3 install manimce
RUN pip3 install scikit-build
COPY ./src/python_utils/requirements.txt /requirements.txt
RUN export CC=gcc-8 && export CXX=g++-8 && pip3 install -r /requirements.txt
RUN pip3 install matplotlib==3.2
# RUN pip3 install wheel /tmp/SimpleITK-1.2.0-cp37-cp37m-manylinux1_x86_64.whl
RUN pip3 install wheel /tmp/SimpleITK-1.2.3-cp38-cp38-manylinux1_x86_64.whl

# Run Apache and PHP mods
RUN phpenmod mbstring && a2enmod rewrite
RUN curl -sS https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer

RUN a2enmod php8.2
RUN a2enmod headers

# Copy configuration files
RUN rm /etc/apache2/sites-available/*
RUN rm /etc/apache2/sites-enabled/*
RUN rm /etc/apache2/ports.conf
COPY ./config/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN ln -s /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-enabled/000-default.conf
COPY ./config/ports.conf /etc/apache2/ports.conf
COPY ./config/supervisord.conf /etc/supervisord.conf

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir /config
RUN mkdir -p /var/www/laravel
COPY ./config/app.php /config/app.php
COPY ./config/gitignore /config/.gitignore
RUN rm /etc/php/8.2/cli/php.ini
COPY ./config/php.ini /etc/php/8.2/cli/php.ini

# Add Python utility scripts
COPY ./src/python_utils /python_utils

# Add crontab file in the cron directory
ADD ./config/crontab /etc/cron.d/laravel

# Give execution rights on the cron job
RUN chmod 0644 /etc/cron.d/laravel

# Apply cron job
RUN crontab /etc/cron.d/laravel

# Create the log file to be able to run tail
RUN touch /var/log/cron.log

# Entrypoint script
CMD /entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=1m CMD curl -f http://localhost:8081/ || exit 1

