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

// If the const CACHE_VERSION declaration line itself still contains the
// unreplaced {{CACHE_VERSION}} placeholder token, do a targeted replacement.
// This check uses preg_match on the declaration line only — NOT strpos on the
// whole file — so that the {{CACHE_VERSION}} token appearing in comments
// (e.g. explaining the ternary logic) does not trigger the replacement branch
// when sw.js is already correct (hash in place, placeholder absent from code).
// Handles all declaration variants (multiline ternary, single-line ternary,
// and legacy single-line placeholder).
if (preg_match('/^const CACHE_VERSION =[^;]*\'' . preg_quote($placeholder, '/') . '\'/', $content)) {
    // Split into lines, process every line that is part of the CACHE_VERSION
    // declaration block (starts with "const CACHE_VERSION"), and reassemble.
    // This handles both single-line and multi-line declarations correctly.
    $lines = explode("\n", $content);
    $in_block = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^const CACHE_VERSION =/', $line)) {
            $in_block = true;
        }
        if ($in_block) {
            $lines[$i] = str_replace($placeholder, $version, $line);
            // Declaration ends at the first line whose trimmed content ends with a
            // semicolon (with optional trailing comment). This handles single-line
            // declarations and multi-line ternary blocks correctly.
            $trimmed = rtrim($line);
            if (preg_match('/;\s*(\/\/.*)?$/', $trimmed)) {
                $in_block = false;
            }
        }
    }
    $newContent = implode("\n", $lines);
} else {
    // No placeholder found — CACHE_VERSION already has a real hash value.
    // Check if it needs updating (different from current version).
    $newContent = $content; // default: no change
    // The ternary result is the : 'hash' branch, not the comparison 'hash'.
    // The comparison value ('ca4e0f6' in 'ca4e0f6' === 'PLACEHOLDER') is the
    // same only because the deploy script updates both branches simultaneously.
    // The : 'hash' branch is the semantically correct one to match — it is
    // the actual active CACHE_VERSION value that PWA cache invalidation uses.
    if (preg_match('/^\s+: \'([a-z0-9_-]+)\'/m', $content, $m)) {
        $current = $m[1];
        if ($current !== $version) {
            // Version mismatch — update all occurrences of the old hash in the
            // CACHE_VERSION block to the new version.
            $lines = explode("\n", $content);
            $in_block = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^const CACHE_VERSION =/', $line)) {
                    $in_block = true;
                }
                if ($in_block) {
                    $lines[$i] = str_replace("'{$current}'", "'{$version}'", $line);
                    if (preg_match('/;\s*(\/\/.*)?$/', rtrim($line))) {
                        $in_block = false;
                    }
                }
            }
            $newContent = implode("\n", $lines);
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
