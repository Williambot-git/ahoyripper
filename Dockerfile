FROM python:3.12-slim

RUN apt-get update && apt-get install -y \
    ffmpeg \
    nginx \
    php \
    php-fpm \
    php-mbstring \
    php-curl \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir yt-dlp

WORKDIR /app

COPY public/ ./public/
COPY src/ ./src/

# Install nginx and PHP for the web layer
RUN apt-get update && apt-get install -y \
    nginx \
    php \
    php-fpm \
    php-mbstring \
    php-curl \
    && rm -rf /var/lib/apt/lists/*

# Configure php-fpm
RUN sed -i 's/listen = \/run\/php\/php.*\.sock/listen = 127.0.0.1:9000/' /etc/php/*/fpm/pool.d/*.conf || true

# Nginx config
COPY deploy/nginx-docker.conf /etc/nginx/sites-available/default

EXPOSE 8080

CMD service php*-fpm start && nginx -g 'daemon off;'