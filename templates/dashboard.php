<?php
use App\Domain\Submissions\Presenter;
use App\Support\Icons;
use App\Support\Time;

$range = ['from' => $from, 'to' => $to];
$tz = $ctx->timezone();
$pct = static fn (int $n): string => $total > 0 ? (string) round($n / $total * 100) . '%' : '0%';
$statusIcons = ['new' => 'flag', 'open' => 'clock', 'closed' => 'inbox', 'spam' => 'other'];
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted page-sub">Inquiries from <?= e(count($websiteRows)) ?> website<?= count($websiteRows) === 1 ? '' : 's' ?> · <?= e(date_create($from)->format('d M')) ?> – <?= e(date_create($to)->format('d M Y')) ?></p>
    </div>
</div>

<?= $view->partial('partials/period-form', ['period' => $period, 'from' => $from, 'to' => $to, 'action' => '/']) ?>

<?php if ($noScope): ?>
    <div class="empty">
        <h2 class="h3">No websites assigned yet</h2>
        <p>Your account can only see inquiries from websites assigned to you. Ask a Director or Developer to assign one.</p>
    </div>
<?php else: ?>

<section class="kpis" aria-label="Totals for the period">
    <a class="kpi kpi-total" href="<?= e($ctx->url('/inbox', $range)) ?>">
        <span class="kpi-icon"><?= Icons::svg('inbox', 22) ?></span>
        <span class="kpi-body">
            <span class="kpi-label">Inquiries</span>
            <span class="kpi-value"><?= e(number_format($total)) ?></span>
            <span class="kpi-link">Open in inbox <?= Icons::svg('arrow', 14) ?></span>
        </span>
    </a>
    <?php foreach (Presenter::TYPE_LABELS as $type => $label): ?>
        <a class="kpi kpi-<?= e($type) ?>" href="<?= e($ctx->url('/inbox', $range + ['type' => $type])) ?>">
            <span class="kpi-icon"><?= Icons::svg($type, 20) ?></span>
            <span class="kpi-body">
                <span class="kpi-label"><?= e($label) ?></span>
                <span class="kpi-value"><?= e(number_format($byType[$type])) ?></span>
                <span class="kpi-share"><?= e($pct($byType[$type])) ?> of all</span>
            </span>
        </a>
    <?php endforeach; ?>
</section>

<section class="status-strip" aria-label="Inquiries by status">
    <?php foreach (Presenter::STATUS_LABELS as $status => $label): ?>
        <a class="status-tile status-tile-<?= e($status) ?>" href="<?= e($ctx->url('/inbox', $range + ['status' => $status])) ?>">
            <span class="status-tile-icon"><?= Icons::svg($statusIcons[$status], 16) ?></span>
            <span class="status-tile-label"><?= e($label) ?></span>
            <span class="status-tile-value"><?= e(number_format($byStatus[$status])) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<div class="grid-2">
    <section class="panel" aria-labelledby="by-site">
        <div class="panel-head">
            <h2 id="by-site" class="h3 with-icon"><?= Icons::svg('globe', 18) ?> By website</h2>
            <span class="muted small">split by type</span>
        </div>
        <?php if (!$websiteRows): ?>
            <p class="muted">No websites registered yet.</p>
        <?php else: $maxSite = max(1, ...array_column($websiteRows, 'count')); ?>
            <ul class="barlist">
                <?php foreach ($websiteRows as $w):
                    $parts = [];
                    foreach (Presenter::TYPE_LABELS as $type => $label) {
                        if ($w['types'][$type] > 0) {
                            $parts[] = $label . ' ' . $w['types'][$type];
                        }
                    }
                    $tip = $w['name'] . ': ' . $w['count'] . ($parts ? ' — ' . implode(', ', $parts) : '');
                    ?>
                    <li>
                        <a href="<?= e($ctx->url('/inbox', $range + ['website' => $w['id']])) ?>" title="<?= e($tip) ?>">
                            <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($w['name']), 0, 1))) ?></span>
                            <span class="barlist-name"><?= e($w['name']) ?><?php if ($w['status'] !== 'active'): ?> <span class="tag"><?= e(ucfirst($w['status'])) ?></span><?php endif; ?></span>
                            <span class="barlist-value"><?= e(number_format($w['count'])) ?></span>
                            <svg class="barlist-bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="barlist-track" x="0" y="0" width="100" height="8"></rect>
                                <?php $x = 0.0; foreach (array_keys(Presenter::TYPE_LABELS) as $type):
                                    if ($w['types'][$type] <= 0) { continue; }
                                    $wd = $w['types'][$type] / $maxSite * 100; ?>
                                    <rect class="bar-<?= e($type) ?> seg" x="<?= round($x, 3) ?>" y="0" width="<?= round($wd, 3) ?>" height="8"></rect>
                                <?php $x += $wd; endforeach; ?>
                            </svg>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <ul class="legend legend-compact" aria-label="Legend">
                <?php foreach (Presenter::TYPE_LABELS as $type => $label): ?>
                    <li><span class="swatch swatch-<?= e($type) ?>" aria-hidden="true"></span><?= e($label) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <?= $view->partial('partials/daily-chart', ['daily' => $daily, 'baseQuery' => [], 'ctx' => $ctx]) ?>
    </section>
</div>

<section class="panel" aria-labelledby="recent">
    <div class="panel-head">
        <h2 id="recent" class="h3 with-icon"><?= Icons::svg('clock', 18) ?> Recent inquiries</h2>
        <a href="<?= e($ctx->url('/inbox', $range)) ?>" class="small">View all</a>
    </div>
    <?php if (!$recent): ?>
        <p class="muted">No inquiries in this period.</p>
    <?php else: ?>
        <ul class="recent">
            <?php foreach ($recent as $r): ?>
                <li>
                    <a href="<?= e($ctx->url('/inbox/' . $r['id'])) ?>">
                        <span class="recent-main">
                            <?= Icons::typeBadge($r['form_type'], Presenter::TYPE_LABELS[$r['form_type']]) ?>
                            <bdi class="recent-who"><?= e(Presenter::customer($r)) ?></bdi>
                            <?php if ($r['status'] === 'new'): ?><span class="status status-new">New</span><?php endif; ?>
                        </span>
                        <span class="recent-meta"><?= e($r['website_name']) ?> · <time datetime="<?= e(Time::iso($r['received_at'])) ?>"><?= e(Time::local($r['received_at'], $tz)) ?></time></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
