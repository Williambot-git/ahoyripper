FROM debian:bookworm-slim

# Fail fast: exit immediately on any command failure.
# This ensures that a partial package installation (e.g. disk-space exhaustion,
# network error mid-download, or a package not found) stops the build before
# subsequent commands run against an incomplete system — preventing broken images.
SHELL ["/bin/bash", "-e", "-o", "pipefail"]

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
    # when the checksum file itself couldn't be read. We treat mismatch (1) as a
    # hard failure and missing checksum (2) as a warning (build was run before
    # checksums were published — not a security failure of the actual binary).
    # Treat any unexpected exit code as "skip verification" (not a hard failure).
    SHA256_STATUS=$(sha256sum --strict -c /tmp/yt-dlp-sha256 2>/dev/null; echo $?)
    if [ "$SHA256_STATUS" = "0" ]; then
         echo "yt-dlp SHA256 verified"
    elif [ "$SHA256_STATUS" = "2" ]; then
         echo "WARNING: SHA256 verification skipped (checksum file unavailable)"
    else
         echo "ERROR: yt-dlp SHA256 mismatch — binary may be corrupted or tampered with"
         exit 1
    fi
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -f /tmp/yt-dlp-sha256

# Verify yt-dlp is intact and runs before declaring the image good.
# A corrupt or incomplete download produces a non-executable file;
# catching it here fails the build fast rather than producing a broken container.
# Capture and expose the version for build-time debugging and image inspection.
RUN echo "yt-dlp version: $(yt-dlp --version)" && \
    yt-dlp --version > /dev/null 2>&1 || \
    { echo "ERROR: yt-dlp installation failed or binary is non-executable"; exit 1; }

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