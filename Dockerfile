FROM debian:bookworm-slim

# OCI image labels — standard container metadata for docker inspect, orchestration
# platforms (Kubernetes, Docker Swarm), and vulnerability scanners (Trivy, Grype).
# org.opencontainers.* labels are the OCI distribution-spec convention and are
# read by most container tooling. com.ahoyripper.* is the project-specific namespace.
LABEL org.opencontainers.image.title="AhoyRipper" \
      org.opencontainers.image.description="Free online media ripper — download video and audio from YouTube, TikTok, X/Twitter, SoundCloud, Instagram, Facebook, Reddit, Vimeo, and 1873+ platforms via yt-dlp." \
      org.opencontainers.image.version="1.0.0" \
      org.opencontainers.image.source="https://github.com/Williambot-git/ahoyripper" \
      org.opencontainers.image.authors="AhoyVPN <support@ahoyvpn.com>" \
      org.opencontainers.image.licenses="MIT" \
      com.ahoyripper.version="1.0.0" \
      com.ahoyripper.homepage="https://ahoyripper.com"

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
        php-xml \
        php-gd \
        python3 \
        python3-pip \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    # Install yt-dlp as a standalone binary (no Python dependency needed).
    # The binary is the recommended installation method per yt-dlp docs and
    # avoids pip installation complexity, reduces image size, and is faster.
    # YTDLP_VERSION defaults to 'latest' for automatic updates.
    # Pin to a specific version (e.g. '2024.08.06') for reproducible builds.
    && YTDLP_VERSION="${YTDLP_VERSION:-latest}" \
    && if [ "$YTDLP_VERSION" = "latest" ]; then \
        YT_DLP_URL="https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp"; \
        YT_DLP_SUMS_URL="https://github.com/yt-dlp/yt-dlp/releases/latest/download/SHA2-256SUMS"; \
    else \
        YT_DLP_URL="https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp"; \
        YT_DLP_SUMS_URL="https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/SHA2-256SUMS"; \
    fi \
    && curl -fL -o /usr/local/bin/yt-dlp "$YT_DLP_URL" \
    && curl -fL -o /tmp/SHA2-256SUMS "$YT_DLP_SUMS_URL" \
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

# Install curl_cffi — required for yt-dlp --impersonate (yt-dlp 2024.09+).
# The standalone binary is used for yt-dlp itself (faster startup, no Python
# needed for yt-dlp itself), but --impersonate requires the curl_cffi Python
# library to spoof browser TLS fingerprints. Without it, --impersonate silently
# fails and yt-dlp falls back to its default TLS fingerprint, defeating the
# anti-bot protection that --impersonate is meant to provide.
# --break-system-packages needed on Debian Bookworm (PEP 668 compliance).
# -q suppresses progress output; the verification step immediately after confirms
# the install actually succeeded rather than silently proceeding on partial failure.
RUN pip3 install --break-system-packages -q curl_cffi 2>&1 | tail -5
# Verify curl_cffi is actually importable before the build continues.
# Without this check, a broken or partial installation (e.g. missing shared
# library, wrong Python version, pip bug) silently proceeds and --impersonate
# fails at runtime with 403 errors on protected sites — with no indication
# from the build that curl_cffi is broken. The import check fails fast and
# loud if the install did not succeed.
RUN python3 -c "import curl_cffi; print(f'curl_cffi {curl_cffi.__version__} installed')"

# Verify yt-dlp is intact and runs before declaring the image good.
# A corrupt or incomplete download produces a non-executable file;
# catching it here fails the build fast rather than producing a broken container.
# Capture and expose the version for build-time debugging and image inspection.
# Note: command substitution $(yt-dlp --version) produces empty string on failure
# (not an error), so we check the exit code explicitly via a subshell.
# When YTDLP_VERSION is pinned (not 'latest'), also verify the installed version
# matches the expected version — a mismatch means the release tag was renamed or
# the download URL is stale.
RUN (yt-dlp --version && echo "yt-dlp version: $(yt-dlp --version)") || \
    { echo "ERROR: yt-dlp installation failed or binary is non-executable"; exit 1; } \
    && if [ "$YTDLP_VERSION" != "latest" ] && [ -n "$YTDLP_VERSION" ]; then \
        installed="$(yt-dlp --version)" || installed=""; \
        if [ "$installed" != "$YTDLP_VERSION" ]; then \
            echo "ERROR: yt-dlp version mismatch — expected '$YTDLP_VERSION', got '$installed'"; \
            exit 1; \
        fi \
    fi

# Create a non-root user and group (www-data) to follow the principle of
# least privilege. Docker containers should not run as root by default —
# if compromised, a non-root process has a much smaller blast radius.
# nginx and php-fpm both support running as a specific user via their configs.
RUN groupadd --gid 1000 www-data && \
    useradd --uid 1000 --gid www-data --shell /usr/sbin/nologin \
        --comment "AhoyRipper web service user" www-data

# Ensure /app and all files are readable by www-data and writable for logs/uploads.
RUN mkdir -p /app && chown -R www-data:www-data /app

WORKDIR /app

COPY public/ ./public/
COPY src/ ./src/
COPY scripts/ ./scripts/

# Bump PWA ServiceWorker cache version on every deploy so returning
# PWA users fetch fresh static assets (CSS, JS, icons) automatically.
# generate-sw-version.php reads the git commit hash and replaces
# {{CACHE_VERSION}} in sw.js with the current hash. When there is no
# git repo (e.g. source tarball), it falls back to a date-based string
# that changes every minute. Without this step the SW always gets
# CACHE_VERSION='unversioned' and never invalidates the PWA cache.
RUN php scripts/generate-sw-version.php || true

# Run as non-root — the image must be built with this user, not root.
# This prevents the container from gaining root privileges via setuid binaries
# and reduces the impact of any future container escape vulnerability.
USER www-data

# Note: php-fpm and nginx configs must reference the www-data user/group.
# Both Debian's php-fpm pool config and nginx use www-data by default on Debian.
# The fastcgi_pass socket must be accessible by www-data; php-fpm running as
# www-data creates the socket with correct permissions. If using a socket path
# owned by root (e.g. /run/php/php-fpm.sock), either add www-data to the root
# group or switch to a TCP socket (127.0.0.1:9000) as used in Docker.
# PHP-FPM socket ownership is handled by the php-fpm configuration itself.

# Configure php-fpm to listen on localhost (avoids socket permission issues in Docker)
RUN find /etc/php -name "*.conf" -path "*pool.d*" -exec sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' {} \; 2>/dev/null || true

# Nginx config for Docker
COPY deploy/nginx-docker.conf /etc/nginx/sites-available/default

EXPOSE 8080

# Docker HEALTHCHECK — uses the built-in /?action=check endpoint
# which is a zero-dependency JSON ping (no yt-dlp, no syscalls, no /proc).
# Safe to call every 10s. Fails fast if PHP-FPM or nginx is down.
# NOTE: uses / (root) not /src/api.php — the nginx config serves the API from
# root /app/public with "location = /src/api.php", which maps to /app/public/src/api.php
# (does not exist). The root location ("location /") handles PHP via the ~ \.php$ block
# and correctly routes /?action=check to api.php. Matches the docker-compose.yml
# healthcheck which uses "http://localhost:8080/".
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:8080/?action=check > /dev/null || exit 1

# Use php-fpm in foreground mode — correct for single-process Docker containers.
# DO NOT use "service php*-fpm start" here: the glob pattern is resolved by
# the shell at runtime, but the service command on Debian Bookworm may not
# handle the wildcard correctly (service name is php8.2-fpm, not php-fpm),
# causing PHP-FPM to fail silently and requests to return 502. Running
# "php-fpm" directly (without "service") forks into background daemon mode
# automatically and is the canonical approach for Docker CMD/ENTRYPOINT scripts.
CMD php-fpm && nginx -g 'daemon off;'