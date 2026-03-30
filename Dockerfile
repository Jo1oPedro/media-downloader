ARG PHP_VERSION
FROM php:${PHP_VERSION} AS app

ARG APP_DIR=/var/www/app
ARG APP_ENV=local
ENV APP_ENV=${APP_ENV}

RUN apt-get update -y && apt-get install -y --no-install-recommends \
    git \
    apt-utils \
    supervisor \
    nano \
    zlib1g-dev \
    libzip-dev \
    unzip \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libbrotli-dev \
    libssl-dev \
    ffmpeg \
    python3 \
    python3-pip \
    curl \
    supervisor \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
      sockets \
      mysqli \
      pdo \
      pdo_mysql \
      session \
      xml \
      zip \
      iconv \
      simplexml \
      pcntl \
      gd \
      fileinfo

RUN pecl install redis \
 && docker-php-ext-enable redis \
 && yes '' | pecl install swoole \
 && docker-php-ext-enable swoole

RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

RUN pip3 install --no-cache-dir --break-system-packages yt-dlp

COPY yt-dlp.conf /etc/yt-dlp.conf

RUN curl -sS https://getcomposer.org/installer | php \
    -- --install-dir=/usr/local/bin --filename=composer

COPY ./php/extra-php.ini    "$PHP_INI_DIR/conf.d"

WORKDIR $APP_DIR
RUN chown www-data:www-data $APP_DIR

COPY --chown=www-data:www-data . .

RUN rm -rf vendor \
 && composer install --no-interaction \
 && composer update --no-interaction \

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN rm -rf vendor \
    && composer install --prefer-dist --no-scripts --no-progress --no-interaction \
    $(if [ "$APP_ENV" = "production" ]; then echo "--no-dev"; fi) \
    && composer dump-autoload --optimize

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM app AS app_dev

RUN pecl install xdebug \
 && docker-php-ext-enable xdebug