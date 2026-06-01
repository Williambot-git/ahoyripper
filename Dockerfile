FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
        curl \
        ffmpeg \
        nginx \
        php \
        php-fpm \
        php-mbstring \
        php-curl \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    # Install yt-dlp as a standalone binary (no Python dependency needed).
    # The binary is the recommended installation method per yt-dlp docs and
    # avoids pip installation complexity, reduces image size, and is faster.
    && curl -L -o /usr/local/bin/yt-dlp \
        https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp

# Verify yt-dlp is intact and runs before declaring the image good.
# A corrupt or incomplete download produces a non-executable file;
# catching it here fails the build fast rather than producing a broken container.
# Capture and expose the version for build-time debugging and image inspection.
RUN echo "yt-dlp version: $(yt-dlp --version)" \
    && yt-dlp --version > /dev/null 2>&1 \
    || { echo "ERROR: yt-dlp installation failed or binary is non-executable"; exit 1; }

WORKDIR /app

COPY public/ ./public/
COPY src/ ./src/

# Configure php-fpm to listen on localhost (avoids socket permission issues in Docker)
RUN find /etc/php -name "*.conf" -path "*pool.d*" -exec sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' {} \; 2>/dev/null || true

# Nginx config for Docker
COPY deploy/nginx-docker.conf /etc/nginx/sites-available/default

EXPOSE 8080

# Docker HEALTHCHECK — uses the built-in /src/api.php?action=check endpoint
# which is a zero-dependency JSON ping (no yt-dlp, no syscalls, no /proc).
# Safe to call every 10s. Fails fast if PHP-FPM or nginx is down.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:8080/src/api.php?action=check > /dev/null || exit 1

CMD service php*-fpm start && nginx -g 'daemon off;'"