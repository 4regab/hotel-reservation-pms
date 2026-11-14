FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY . /app
# ponytail: the PHP built-in server keeps the demo to one small image. Put nginx + php-fpm in front for real traffic.
ENV PHP_CLI_SERVER_WORKERS=4
