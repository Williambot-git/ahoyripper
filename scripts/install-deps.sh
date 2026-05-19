#!/bin/bash
# AhoyRipper - Dependency Installation Script
# Run as root or with sudo

set -e

echo "==> Installing yt-dlp..."
if command -v yt-dlp &>/dev/null; then
    yt-dlp --version
fi

# Install yt-dlp via pip or git
if ! command -v yt-dlp &>/dev/null; then
    pip install -q yt-dlp 2>/dev/null || pip3 install -q yt-dlp 2>/dev/null || \
    pip install --break-system-packages -q yt-dlp 2>/dev/null || \
    pip3 install --break-system-packages -q yt-dlp
fi

# Keep yt-dlp updated (--self-update is the correct flag; -U is deprecated)
yt-dlp --self-update

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