FROM php:8.5-cli-alpine

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions dom mbstring pcntl sockets intl

RUN apk add --no-cache curl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/lite-strip
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader
COPY . .

EXPOSE 8080
CMD ["php", "server.php"]
