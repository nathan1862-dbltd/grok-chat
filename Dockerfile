FROM php:8.2-alpine

RUN apk add --no-cache libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev \
    && docker-php-ext-install curl \
    && apk del .build-deps

WORKDIR /var/www/html

COPY index.php Parsedown.php parsedown.css ./

RUN printf '%s\n' \
    'upload_max_filesize=20M' \
    'post_max_size=21M' \
    > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
