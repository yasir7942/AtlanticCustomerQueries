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
    <link rel="icon" href="<?= e($base . '/assets/favicon.svg') ?>?v=<?= e(\App\App::VERSION) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e($base . '/assets/apple-touch-icon.png') ?>?v=<?= e(\App\App::VERSION) ?>">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="stylesheet" href="<?= e($base . '/assets/app.css') ?>?v=<?= e(\App\App::VERSION) ?>">
    <script src="<?= e($base . '/assets/app.js') ?>?v=<?= e(\App\App::VERSION) ?>" defer></script>
</head>
<body class="bare">
<main id="main" class="bare-main">
    <p class="brand brand-bare"><?= e($appName) ?></p>
    <?= $content ?>
</main>
</body>
</html>
