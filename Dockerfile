FROM php:8.3-fpm

# Install Nginx and PHP extension dependencies
RUN apt-get update \
 && apt-get install -y curl gnupg2 ca-certificates \
 && echo "deb http://nginx.org/packages/debian bookworm nginx" \
    | tee /etc/apt/sources.list.d/nginx.list \
 && curl -fsSL https://nginx.org/keys/nginx_signing.key \
    | gpg --dearmor -o /etc/apt/trusted.gpg.d/nginx.gpg \
 && apt-get update \
 && apt-get install -y nginx \
    libzip-dev libicu-dev libpng-dev zlib1g-dev \
    libjpeg-dev libfreetype6-dev libxml2-dev \
    libcurl4-openssl-dev libsodium-dev zip unzip msmtp \
    procps supervisor \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure zip \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
        bcmath sockets zip intl pdo_mysql opcache sodium gd \
 && mkdir -p /var/run/nginx \
 && rm -rf /var/lib/apt/lists/*

# Nginx logs to stdout/stderr
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

# PHP config
COPY ./docker-config/php/php.ini /usr/local/etc/php/php.ini
COPY ./docker-config/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Mail (Mailcatcher)
COPY ./docker-config/php/msmtprc /etc/msmtprc

# Nginx config
RUN rm -rf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
           /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY ./docker-config/nginx/nginx.conf /etc/nginx/nginx.conf
COPY ./docker-config/nginx/app.conf /etc/nginx/conf.d/app.conf

# Supervisor (nginx + php-fpm)
COPY ./docker-config/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html
COPY . .

EXPOSE 9000 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
