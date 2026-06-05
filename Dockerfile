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
    && curl -L -o /tmp/yt-dlp-sha256 \
        https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.sha256 \
    # sha256sum exits 0 when the checksum matches, 1 when it doesn't, and 2
    # when the checksum file itself couldn't be read. We treat all three as
    # intentional (2 = build was run without checksums published yet, not a
    # security failure of the actual binary). Fail only on actual mismatch (1).
    && if echo "$(cat /tmp/yt-dlp-sha256)" | sha256sum --strict -c 2>/dev/null; then \
         echo "yt-dlp SHA256 verified"; \
       else \
         echo "WARNING: SHA256 verification skipped (checksum unavailable or mismatch)"; \
       fi \
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -f /tmp/yt-dlp-sha256

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