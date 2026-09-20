<?php
/**
 * AhoyRipper OpenSearch Description
 *
 * Converted from static XML to PHP to support dynamic base URL via the
 * Host header. This enables correct OpenSearch auto-discovery for custom-domain
 * deployments (e.g. rip.example.com) where the search template URLs must point
 * to the deployer's own domain, not the default ahoyripper.com.
 *
 * How it works:
 * - index.php references this file with <link rel="search" type="application/opensearchdescription+xml">
 * - nginx serves this file (opensearch.xml, not .php) through its PHP-FPM location
 *   via the rewrite rule below, preserving the correct Content-Type header
 * - PHP derives the canonical base URL from the incoming Host header
 * - nginx then adds the correct Content-Type (application/opensearchdescription+xml)
 *   via the "location = /opensearch.xml" block (since nginx serves the final response,
 *   not PHP-FPM directly for this endpoint)
 *
 * HTTPS is guaranteed via HSTS preload, so no mixed-content risk.
 *
 * Note: OpenSearch 1.1 does not define an <AdultContent> element.
 * The static XML previously included <AdultContent> which is not part of the
 * spec and is ignored by all browsers — it has been removed.
 */
$BASE_URL = (
    isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ? 'https' : 'http'
) . '://' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'ahoyripper.com', ENT_QUOTES, 'UTF-8');
header('Content-Type: application/opensearchdescription+xml; charset=utf-8');
?><?xml version="1.0" encoding="UTF-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
  <ShortName>AhoyRipper</ShortName>
  <Description>Download video and audio from YouTube, TikTok, Twitter, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1872+ other platforms. Free forever, no signup required, no ads.</Description>
  <Url type="text/html" method="get" template="<?= $BASE_URL ?>/?url={searchTerms}"/>
  <Url type="application/xhtml+xml" method="get" template="<?= $BASE_URL ?>/?url={searchTerms}"/>
  <Image width="16" height="16" type="image/png"><?= $BASE_URL ?>/favicon-180.png</Image>
  <Image width="64" height="64" type="image/png"><?= $BASE_URL ?>/favicon-512.png</Image>
  <Language>en-us</Language>
  <InputEncoding>UTF-8</InputEncoding>
  <OutputEncoding>UTF-8</OutputEncoding>
</OpenSearchDescription>
