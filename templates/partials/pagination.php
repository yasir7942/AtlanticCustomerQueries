<?php
/** @var int $page @var int $pages @var string $path @var array $query */
if ($pages <= 1) {
    return;
}
$link = fn (int $p): string => $ctx->url($path, $query + ['page' => $p > 1 ? $p : null]);
$window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn ($p) => $p >= 1 && $p <= $pages));
sort($window);
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a class="btn btn-small" href="<?= e($link($page - 1)) ?>" rel="prev">Previous</a>
    <?php endif; ?>
    <ul>
        <?php $prev = 0; foreach ($window as $p): ?>
            <?php if ($p - $prev > 1): ?><li class="gap" aria-hidden="true">…</li><?php endif; ?>
            <li><a href="<?= e($link($p)) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= e($p) ?></a></li>
        <?php $prev = $p; endforeach; ?>
    </ul>
    <?php if ($page < $pages): ?>
        <a class="btn btn-small" href="<?= e($link($page + 1)) ?>" rel="next">Next</a>
    <?php endif; ?>
</nav>
