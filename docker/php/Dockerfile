# PHP-FPM image used by all four services, the job runners and the one-off migrate job.
# Which service a container runs is decided by its environment (SERVICE_NAME and its own credentials), not by the image.
# Debian, not Alpine: Microsoft's ODBC driver for SQL Server is packaged for Debian's apt.
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl gnupg unixodbc-dev \
 && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
 && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list -o /etc/apt/sources.list.d/mssql-release.list \
 && apt-get update \
 && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
 && pecl install pdo_sqlsrv-5.13.3 \
 && docker-php-ext-enable pdo_sqlsrv \
 && docker-php-ext-install opcache \
 && apt-get purge -y --auto-remove curl gnupg unixodbc-dev \
 && rm -rf /var/lib/apt/lists/* /tmp/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-harborlight.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-harborlight.conf

WORKDIR /app
COPY --chown=www-data:www-data . /app

# Runs as an unprivileged user. The code is read-only data for it.
USER www-data
EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
