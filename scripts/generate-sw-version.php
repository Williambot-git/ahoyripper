#!/usr/bin/env php
<?php
/**
 * Generate SW cache version for AhoyRipper PWA service worker.
 *
 * Run at deploy time to replace {{CACHE_VERSION}} in sw.js with the
 * current git commit short hash. This bumps the PWA cache version on
 * every deploy, ensuring PWA users fetch fresh static assets (CSS, JS,
 * icons) when a new version is deployed.
 *
 * Usage:
 *   php scripts/generate-sw-version.php
 *
 * Exit codes:
 *   0 — version generated and sw.js updated
 *   1 — not in a git repo or sw.js not found (no-op, non-fatal)
 *   2 — sw.js not writable
 */

$swFile = __DIR__ . '/../public/sw.js';

if (!is_readable($swFile)) {
    fwrite(STDERR, "generate-sw-version: sw.js not found at {$swFile}, skipping.\n");
    exit(1);
}

// Get short git hash — fallback to date-based string if not in a repo
$hash = trim(@exec('git rev-parse --short HEAD 2>/dev/null') ?: '');
if ($hash === '') {
    // Not in a git repo — use YYYYMMDD-HHMM as a monotonically-increasing fallback.
    // Using date is safe: it changes every minute, forcing a cache bump even
    // without git. git-rev-parse is preferred when available (unique per commit).
    $hash = date('ymd-His');
}

$version = $hash;

// Replace the placeholder in sw.js
$content = file_get_contents($swFile);
$placeholder = '{{CACHE_VERSION}}';

if (strpos($content, $placeholder) === false) {
    // No placeholder found — either already replaced or file format changed.
    // If the current version doesn't match the hash, the file may need updating.
    // Check whether the deployed version is stale by seeing if CACHE_VERSION
    // is still the old literal 'v1' (the hardcoded value before this script existed).
    if (preg_match('/const CACHE_VERSION = \'([a-z0-9-]+)\';/', $content, $m)) {
        $current = $m[1];
        if ($current === $version) {
            // Already at the right version — nothing to do.
            echo "generate-sw-version: sw.js already at version {$version}\n";
            exit(0);
        }
        // Current version differs from desired — replace it.
        $newContent = preg_replace(
            '/const CACHE_VERSION = \'[^\']*\';/',
            "const CACHE_VERSION = '{$version}';",
            $content
        );
    } else {
        fwrite(STDERR, "generate-sw-version: could not parse CACHE_VERSION in sw.js, skipping.\n");
        exit(1);
    }
} else {
    $newContent = str_replace($placeholder, $version, $content);
}

if ($newContent === $content) {
    echo "generate-sw-version: no change needed (already at {$version})\n";
    exit(0);
}

if (!is_writable($swFile)) {
    fwrite(STDERR, "generate-sw-version: sw.js is not writable, skipping.\n");
    exit(2);
}

file_put_contents($swFile, $newContent);
echo "generate-sw-version: updated sw.js to version {$version}\n";
exit(0);
