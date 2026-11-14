# PHP-FPM image used by all four services, the job runners and the one-off migrate job.
# Which service a container runs is decided by its environment (SERVICE_NAME and its own credentials), not by the image.
FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo_mysql opcache \
 && rm -rf /var/cache/apk/* /tmp/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-harborlight.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-harborlight.conf

WORKDIR /app
COPY --chown=www-data:www-data . /app

# Runs as an unprivileged user. The code is read-only data for it.
USER www-data
EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
