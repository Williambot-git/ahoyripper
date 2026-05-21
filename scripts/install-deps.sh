#!/bin/bash
# AhoyRipper - Dependency Installation Script
# Run as root or with sudo

set -e

echo "==> Installing yt-dlp..."
if command -v yt-dlp &>/dev/null; then
    yt-dlp --version
fi

# Detect available pip and install yt-dlp.
# Check in order of preference, stopping at the first working one.
echo "==> Checking for pip..."
PIP_BIN=""
for _pip in pip pip3 pip3.11 pip3.12 python3 -m pip; do
    if $_pip --version &>/dev/null; then
        PIP_BIN="$_pip"
        break
    fi
done

if [ -z "$PIP_BIN" ]; then
    echo "  ! pip not found. Installing python3-pip..."
    apt-get install -y python3-pip > /dev/null 2>&1
    for _pip in pip pip3 python3 -m pip; do
        if $_pip --version &>/dev/null; then
            PIP_BIN="$_pip"
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
    # Try pip install, then with --break-system-packages, then with --user
    _install_yt_dlp yt-dlp || \
    _install_yt_dlp --break-system-packages yt-dlp || \
    _install_yt_dlp --user yt-dlp || {
        echo "  ! ERROR: yt-dlp installation failed via pip. Trying standalone binary..."
        # Last resort: download the standalone binary directly
        curl -L -o /usr/local/bin/yt-dlp \
            https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
            2>/dev/null && chmod +x /usr/local/bin/yt-dlp
    }

    if ! command -v yt-dlp &>/dev/null; then
        echo "  ! ERROR: yt-dlp is not installed and could not be installed."
        echo "    Please install manually: pip install yt-dlp"
        exit 1
    fi
    echo "  Installed: $(yt-dlp --version 2>&1 | head -1)"
fi

# Keep yt-dlp updated (--self-update is the correct flag; -U is deprecated)
echo "==> Updating yt-dlp..."
yt-dlp --self-update 2>&1 | tail -1

echo "==> Installing ffmpeg..."
apt-get install -y ffmpeg > /dev/null 2>&1
ffmpeg -version | head -1

echo "==> Installing PHP and modules..."
apt-get install -y php php-fpm php-mbstring php-curl > /dev/null 2>&1
php -v | head -1

echo "==> Installing Nginx..."
apt-get install -y nginx > /dev/null 2>&1
nginx -v

echo "==> All deps installed."
echo "  - yt-dlp: $(yt-dlp --version)"
echo "  - ffmpeg: $(ffmpeg -version 2>&1 | head -1)"
echo "  - PHP: $(php -v | head -1)"