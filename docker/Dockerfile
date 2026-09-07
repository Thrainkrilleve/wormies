# Stage 1: Build environment (Composer, Node, Assets)
FROM dunglas/frankenphp:builder-php8.4 AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf \
    unzip \
    libpq-dev \
    libonig-dev \
    libssl-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    libzip-dev \
    nodejs \
    npm \
    wget \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    bz2 \
    pcntl \
    pdo_mysql \
    sockets \
    zip \
    redis \
    && rm -rf /tmp/* /var/tmp/*

# Install Supercronic
RUN arch="$(uname -m)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.38/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php /app/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

WORKDIR /app

# Copy the entire Laravel application code
COPY . /app

# Install Composer dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --prefer-dist --optimize-autoloader

ARG VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST \
    VITE_REVERB_PORT \
    VITE_REVERB_SCHEME
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN php artisan wayfinder:generate
RUN npm install
RUN php artisan sde:download
RUN php artisan generate:static-data
RUN npm run build

# ---
# Stage 2: Production environment
FROM dunglas/frankenphp:php8.4

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    procps \
    && apt-get autoremove -y && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

RUN install-php-extensions \
    bz2 \
    pcntl \
    pdo_mysql \
    sockets \
    zip \
    redis \
    && rm -rf /tmp/* /var/tmp/*

WORKDIR /app

COPY --from=builder /app /app
COPY --from=builder /usr/bin/supercronic /usr/bin/supercronic
COPY --from=builder /etc/supercronic/laravel /etc/supercronic/laravel

# Copy custom php.ini directly into PHP conf.d
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

RUN chown -R www-data:www-data /app

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
