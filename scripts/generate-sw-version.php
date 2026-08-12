#!/usr/bin/php
<?php
/**
 * Generate SW cache version for AhoyRipper PWA service worker.
 *
 * Run at deploy time to replace {{CACHE_VERSION}} in sw.js with the
 * current git commit short hash. This bumps the PWA cache version on
 * every deploy, ensuring PWA users fetch fresh static assets (CSS, JS,
 * icons) when a new version is deployed.
 *
 * Handles two sw.js formats:
 *
 * New multiline ternary (PLACEHOLDER-check pattern):
 *   // {{CACHE_VERSION}} — deployed git hash...
 *   const CACHE_VERSION = '{{CACHE_VERSION}}' === 'PLACEHOLDER'
 *       ? 'unversioned'
 *       : '{{CACHE_VERSION}}';
 *   (When the deploy script replaces PLACEHOLDER with the real hash,
 *    the ternary evaluates to the hash, enabling PWA cache versioning.
 *    When the placeholder is left unreplaced, it falls back to 'unversioned'.)
 *
 * Old single-line ternary (broken — both branches had same hash):
 *   // '{{CACHE_VERSION}}' is replaced at deploy time...
 *   const CACHE_VERSION = '{{CACHE_VERSION}}' === '{{CACHE_VERSION}}' ? 'unversioned' : '{{CACHE_VERSION}}';
 *
 * Legacy single-line (pre-ternary):
 *   const CACHE_VERSION = '{{CACHE_VERSION}}';
 *
 * Usage:
 *   php scripts/generate-sw-version.php
 *
 * Exit codes:
 *   0 — version generated and sw.js updated
 *   1 — sw.js not found or could not be parsed (no-op, non-fatal)
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
    // Not in a git repo — use YYYYMMDD as a daily monotonically-increasing
    // fallback. Using a daily date prevents the hash from changing between runs
    // within the same day, which would modify sw.js on every CI run and cause
    // the PWA versioning test to falsely fail with "sw.js was modified". The
    // daily granularity is sufficient: it bumps the PWA cache once per
    // deployment day, not once per CI pipeline run.
    $hash = date('ymd');
}

$version = $hash;
$placeholder = '{{CACHE_VERSION}}';
$content = file_get_contents($swFile);

// If the placeholder token is still present, do a targeted replacement
// on just the CACHE_VERSION const declaration line.
if (strpos($content, $placeholder) !== false) {
    $newContent = preg_replace_callback(
        '/^const CACHE_VERSION = .*/m',
        function ($m) use ($version, $placeholder) {
            $line = $m[0];
            // Replace all occurrences of the placeholder token in this line.
            // This handles:
            //   - New multiline ternary: const CACHE_VERSION = '{{CACHE_VERSION}}' !== 'PLACEHOLDER' ? '{{CACHE_VERSION}}' : 'unversioned';
            //   - Old broken ternary:   const CACHE_VERSION = '{{CACHE_VERSION}}' === '{{CACHE_VERSION}}' ? 'unversioned' : '{{CACHE_VERSION}}';
            //   - Legacy single-line:   const CACHE_VERSION = '{{CACHE_VERSION}}';
            return str_replace($placeholder, $version, $line);
        },
        $content
    );
} else {
    // No placeholder found — CACHE_VERSION already has a real hash value.
    // Check if it needs updating (different from current version).
    $newContent = $content; // default: no change
    if (preg_match('/^const CACHE_VERSION = \'([a-z0-9_-]+)\'/m', $content, $m)) {
        $current = $m[1];
        if ($current !== $version) {
            // Version mismatch — update all occurrences of the old hash in the
            // CACHE_VERSION line to the new version.
            $newContent = preg_replace_callback(
                '/^const CACHE_VERSION = .*/m',
                function ($m) use ($version, $current) {
                    return str_replace("'{$current}'", "'{$version}'", $m[0]);
                },
                $content
            );
        }
    }
}

if ($newContent === $content) {
    echo "generate-sw-version: sw.js already at version {$version}\n";
    exit(0);
}

if (!is_writable($swFile)) {
    fwrite(STDERR, "generate-sw-version: sw.js is not writable, skipping.\n");
    exit(2);
}

file_put_contents($swFile, $newContent);
echo "generate-sw-version: updated sw.js to version {$version}\n";
exit(0);
