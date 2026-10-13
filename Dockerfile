FROM php:8.5-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        procps \
        libffi-dev \
        libicu-dev \
    && docker-php-ext-install pcntl posix shmop sockets sysvmsg sysvsem sysvshm ffi intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app