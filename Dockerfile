FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    ffmpeg \ 
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pcntl sockets pdo pdo_mysql pdo_pgsql zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /usr/src/app

COPY . .

RUN composer install --working-dir=./webman --no-dev --optimize-autoloader --prefer-dist

EXPOSE 8788

CMD ["php", "webman/start.php", "start"]