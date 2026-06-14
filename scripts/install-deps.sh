#!/bin/bash
# AhoyRipper - Dependency Installation Script
# Run as root or with sudo

set -e

echo "==> Installing yt-dlp..."
if command -v yt-dlp &>/dev/null; then
    yt-dlp --version 2>&1 | head -1
fi

# Detect available pip and install yt-dlp.
# Check in order of preference, stopping at the first working one.
echo "==> Updating package lists..."
apt-get update -qq > /dev/null 2>&1

echo "==> Checking for pip..."
PIP_BIN=""
for pip_candidate in pip3 pip3.11 pip3.12 python3 -m pip; do
    if $pip_candidate --version &>/dev/null; then
        PIP_BIN="$pip_candidate"
        break
    fi
done

if [ -z "$PIP_BIN" ]; then
    echo "  ! pip not found. Installing python3-pip..."
    apt-get install -y python3-pip > /dev/null 2>&1
    for pip_candidate in pip3 python3 -m pip; do
        if $pip_candidate --version &>/dev/null; then
            PIP_BIN="$pip_candidate"
            break
        fi
    done
    if [ -z "$PIP_BIN" ]; then
        echo "  ! ERROR: pip could not be installed or found. Please install pip manually:"
        echo "    sudo apt-get install python3-pip"
        exit 1
    fi
fi
echo "  Using: $PIP_BIN ($($PIP_BIN --version 2>&1 | head -1))"

# Try multiple install strategies in order; stop at the first that succeeds.
# --break-system-packages is needed on Ubuntu 22.04+ for pip to write outside venvs.
echo "==> Installing yt-dlp..."
YTDLP_INSTALLED=false
_install_yt_dlp() {
    $PIP_BIN install -q "$@" 2>&1 && return 0
    return 1
}

if command -v yt-dlp &>/dev/null; then
    echo "  yt-dlp already present: $(yt-dlp --version 2>&1 | head -1)"
else
    echo "  Installing yt-dlp via $PIP_BIN..."
    # Try with --break-system-packages first (Ubuntu 22.04+ / PEP 668 compliance),
    # then without, then --user — stop at the first that succeeds.
    _install_yt_dlp --break-system-packages yt-dlp || \
    _install_yt_dlp yt-dlp || \
    _install_yt_dlp --user yt-dlp || {
        echo "  ! ERROR: yt-dlp installation failed via pip. Trying standalone binary..."
        # Last resort: download the standalone binary directly.
        # The explicit || exit 1 ensures a failed download (network error, 404, etc.)
        # does not silently proceed with a stale or missing binary.
        curl -L -o /usr/local/bin/yt-dlp \
            https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
            || { echo "  ! Standalone binary download failed (network or server error)."; exit 1; }
        [ -s /usr/local/bin/yt-dlp ] || { echo "  ! Downloaded file is empty or missing."; exit 1; }
        chmod +x /usr/local/bin/yt-dlp
        # Verify the binary is actually executable and runs without error.
        # A corrupt download (partial file, wrong binary) fails here before proceeding.
        if ! yt-dlp --version > /dev/null 2>&1; then
            echo "  ! Downloaded yt-dlp binary is not functional."
            exit 1
        fi
        echo "  Installed: $(yt-dlp --version 2>&1 | head -1)"
    }

    if ! command -v yt-dlp &>/dev/null; then
        echo "  ! ERROR: yt-dlp is not installed and could not be installed."
        echo "    Please install manually: pip install yt-dlp"
        exit 1
    fi
    echo "  Installed: $(yt-dlp --version 2>&1 | head -1)"
fi

# Keep yt-dlp updated.
# --self-update works for standalone binary installations but NOT pip-installed
# yt-dlp (pip-installed yt-dlp ignores --self-update silently). Detect the install
# method and use the right upgrade path accordingly.
echo "==> Updating yt-dlp..."
if command -v yt-dlp &>/dev/null; then
    # Validate the existing installation is functional before updating.
    # A broken/corrupted installation (e.g. pip-installed but python pkg broken)
    # will cause self-update to silently skip. Detect this and force reinstall.
    YTDL_BIN=$(command -v yt-dlp)
    if ! yt-dlp --version &>/dev/null; then
        echo "  ! Existing yt-dlp is broken (-V failed). Reinstalling..."
        # Try pip reinstall first, falling back to standalone binary
        if [ -n "$PIP_BIN" ]; then
            $PIP_BIN install -q --force-reinstall yt-dlp 2>&1 | tail -2 || true
        fi
        # If pip reinstall didn't fix it, grab the standalone binary
        if ! yt-dlp --version &>/dev/null; then
            echo "  Installing standalone yt-dlp binary..."
            curl -L -o /usr/local/bin/yt-dlp \
                https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
                2>/dev/null && chmod +x /usr/local/bin/yt-dlp
        fi
    elif pip show yt-dlp &>/dev/null; then
        # pip-installed — use pip to upgrade (pip-installed yt-dlp ignores --self-update)
        $PIP_BIN install -q --upgrade yt-dlp 2>&1 | tail -1
    else
        # standalone binary — download the latest release directly from GitHub
        # rather than relying on self-update, which has known edge-case failures
        # with pinned/dev versions and is unnecessary since we control the binary.
        echo "  Updating standalone yt-dlp binary..."
        curl -L -o /usr/local/bin/yt-dlp \
            https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
            2>/dev/null && chmod +x /usr/local/bin/yt-dlp
        echo "  Updated to: $(yt-dlp --version 2>&1 | head -1)"
    fi
fi

echo "==> Installing ffmpeg..."
apt-get install -y ffmpeg > /dev/null 2>&1
ffmpeg -version | head -1

echo "==> Installing PHP and modules..."
# php-json is required for json_encode/json_decode used throughout api.php.
# It is bundled with php on Ubuntu 22.04+ default installs but may be missing
# on minimal, custom, or non-LTS PHP installations (e.g., ondrej/php PPA,
# Debian bookworm minimal, or custom-compiled PHP). Installing it explicitly
# prevents cryptic "Call to undefined function json_encode()" errors.
apt-get install -y php php-fpm php-mbstring php-curl php-json > /dev/null 2>&1
php -v | head -1

echo "==> Installing Nginx..."
apt-get install -y nginx > /dev/null 2>&1
nginx -v

echo "==> All deps installed."
echo "  - yt-dlp: $(yt-dlp --version 2>&1 | head -1)"
echo "  - ffmpeg: $(ffmpeg -version 2>&1 | head -1)"
echo "  - PHP: $(php -v 2>&1 | head -1)"