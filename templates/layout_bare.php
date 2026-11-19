<?php
$base = isset($ctx) ? $ctx->url('') : ($home ?? '/');
$base = rtrim($base, '/');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e($base . '/assets/favicon.svg') ?>?v=<?= e(asset_version('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e($base . '/assets/apple-touch-icon.png') ?>?v=<?= e(asset_version('apple-touch-icon.png')) ?>">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="stylesheet" href="<?= e($base . '/assets/app.css') ?>?v=<?= e(asset_version('app.css')) ?>">
    <script src="<?= e($base . '/assets/app.js') ?>?v=<?= e(asset_version('app.js')) ?>" defer></script>
</head>
<body class="bare">
<main id="main" class="bare-main">
    <p class="brand brand-bare"><?= e($appName) ?></p>
    <?= $content ?>
</main>
</body>
</html>
