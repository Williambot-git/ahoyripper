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
        php-json \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    # Install yt-dlp as a standalone binary (no Python dependency needed).
    # The binary is the recommended installation method per yt-dlp docs and
    # avoids pip installation complexity, reduces image size, and is faster.
    && curl -L -o /usr/local/bin/yt-dlp \
        https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    && curl -L -o /tmp/SHA2-256SUMS \
        https://github.com/yt-dlp/yt-dlp/releases/latest/download/SHA2-256SUMS \
    # sha256sum exits 0 when the checksum matches, 1 when it doesn't, and 2
    # when the checksum file itself couldn't be read. yt-dlp publishes the full
    # SHA2-256SUMS file (not individual .sha256 files). Extract the line for
    # the plain 'yt-dlp' binary (not yt-dlp.exe, etc.) and verify it.
    # Treat missing checksum file (2) as a warning. Treat mismatch (1) as a hard
    # failure — a corrupt or tampered binary must not be used.
    && YT_DLP_HASH=$(grep 'yt-dlp$' /tmp/SHA2-256SUMS 2>/dev/null | awk '{print $1}') \
    && if [ -n "$YT_DLP_HASH" ]; then \
        echo "$YT_DLP_HASH  /usr/local/bin/yt-dlp" | sha256sum --strict -c -; \
        SHA256_STATUS=$?; \
    else \
        echo "WARNING: SHA2-256SUMS file missing or yt-dlp hash not found — skipping binary verification"; \
        SHA256_STATUS=2; \
    fi \
    && if [ "$SHA256_STATUS" = "0" ]; then \
         echo "yt-dlp SHA256 verified"; \
    elif [ "$SHA256_STATUS" = "2" ]; then \
         echo "WARNING: SHA256 verification skipped (checksum file unavailable)"; \
    else \
         echo "ERROR: yt-dlp SHA256 mismatch — binary may be corrupted or tampered with"; \
         exit 1; \
    fi \
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -f /tmp/SHA2-256SUMS

# Verify yt-dlp is intact and runs before declaring the image good.
# A corrupt or incomplete download produces a non-executable file;
# catching it here fails the build fast rather than producing a broken container.
# Capture and expose the version for build-time debugging and image inspection.
# Note: command substitution $(yt-dlp --version) produces empty string on failure
# (not an error), so we check the exit code explicitly via a subshell.
RUN (yt-dlp --version && echo "yt-dlp version: $(yt-dlp --version)") || \
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

# Use php-fpm in foreground mode — correct for single-process Docker containers.
# DO NOT use "service php*-fpm start" here: the glob pattern is resolved by
# the shell at runtime, but the service command on Debian Bookworm may not
# handle the wildcard correctly (service name is php8.2-fpm, not php-fpm),
# causing PHP-FPM to fail silently and requests to return 502. Running
# "php-fpm" directly (without "service") forks into background daemon mode
# automatically and is the canonical approach for Docker CMD/ENTRYPOINT scripts.
CMD php-fpm && nginx -g 'daemon off;'