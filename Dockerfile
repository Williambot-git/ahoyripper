FROM python:3.12-slim

RUN apt-get update && apt-get install -y \
    ffmpeg \
    nginx \
    php \
    php-fpm \
    php-mbstring \
    php-curl \
    && pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir yt-dlp \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# Verify yt-dlp is intact and runs before declaring the image good.
# A corrupt or incomplete pip install produces an empty/non-executable file;
# catching it here fails the build fast rather than producing a broken container.
RUN yt-dlp --version > /dev/null 2>&1 \
    || { echo "ERROR: yt-dlp installation failed or binary is non-executable"; exit 1; }

WORKDIR /app

COPY public/ ./public/
COPY src/ ./src/

# Configure php-fpm to listen on localhost (avoids socket permission issues in Docker)
RUN find /etc/php -name "*.conf" -path "*pool.d*" -exec sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' {} \; 2>/dev/null || true

# Nginx config for Docker
COPY deploy/nginx-docker.conf /etc/nginx/sites-available/default

EXPOSE 8080

CMD service php*-fpm start && nginx -g 'daemon off;'