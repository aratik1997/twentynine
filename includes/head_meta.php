<?php
/** Shared <head> icon/meta tags. Included by every top-level page. */
require_once __DIR__ . '/helpers.php';
$__pageTitle = $__pageTitle ?? '29 — The Card Game';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($__pageTitle) ?></title>
<meta name="description" content="Play 29 (Twenty-Nine), the classic South Asian trick-taking card game, online with friends — 4-player partnerships or the 3-player variant.">
<link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16.png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
