<?php
/** @var \App\Controllers\Context $ctx */
$user = $ctx->user();
$path = $ctx->request->path;
$nav = [];
if ($user !== null && (int) $user['must_change_password'] !== 1) {
    $nav[] = ['/', 'Dashboard', $path === '/'];
    $nav[] = ['/inbox', 'Inbox', str_starts_with($path, '/inbox')];
    $nav[] = ['/reports', 'Reports', str_starts_with($path, '/reports')];
    if ($ctx->can('websites.view')) {
        $nav[] = ['/websites', 'Websites', str_starts_with($path, '/websites')];
    }
    if ($ctx->can('users.manage')) {
        $nav[] = ['/users', 'Users', str_starts_with($path, '/users')];
    }
    if ($ctx->can('audit.read')) {
        $nav[] = ['/audit', 'Audit log', $path === '/audit'];
    }
    if ($ctx->can('diagnostics.read')) {
        $nav[] = ['/diagnostics', 'Diagnostics', $path === '/diagnostics'];
    }
}
$roleLabels = ['developer' => 'Developer', 'director' => 'Director', 'user' => 'User'];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ? $title . ' · ' : '') . $appName) ?></title>
    <link rel="icon" href="<?= e($ctx->url('/assets/favicon.svg')) ?>?v=<?= e(asset_version('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e($ctx->url('/assets/apple-touch-icon.png')) ?>?v=<?= e(asset_version('apple-touch-icon.png')) ?>">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="stylesheet" href="<?= e($ctx->url('/assets/app.css')) ?>?v=<?= e(asset_version('app.css')) ?>">
    <script src="<?= e($ctx->url('/assets/app.js')) ?>?v=<?= e(asset_version('app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= e($ctx->url('/')) ?>"><?= e($appName) ?></a>
        <?php if ($nav): ?>
        <nav class="mainnav" aria-label="Main">
            <ul>
                <?php foreach ($nav as [$href, $label, $active]): ?>
                    <li><a href="<?= e($ctx->url($href)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php if ($user !== null): ?>
        <div class="usermenu">
            <a href="<?= e($ctx->url('/profile')) ?>" class="usermenu-name" title="Profile and password">
                <?= e($user['name']) ?> <span class="role-tag"><?= e($roleLabels[$user['role']] ?? $user['role']) ?></span>
            </a>
            <form method="post" action="<?= e($ctx->url('/logout')) ?>">
                <?= $ctx->csrf->field() ?>
                <button type="submit" class="btn btn-ghost btn-small">Sign out</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</header>
<main id="main" class="page" tabindex="-1">
    <?= $view->partial('partials/flash', ['flash' => $flash ?? null]) ?>
    <?= $content ?>
</main>
<footer class="footer">
    <span>Times shown in <?= e($ctx->timezone()) ?></span>
</footer>
</body>
</html>
