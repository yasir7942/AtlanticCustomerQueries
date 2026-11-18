<?php
use App\Domain\Submissions\Presenter;

$range = ['from' => $from, 'to' => $to];
$siteSelect = '<div class="field field-inline"><label for="website">Website</label><select id="website" name="website"><option value="">All websites</option>';
foreach ($websites as $w) {
    $siteSelect .= '<option value="' . e($w['id']) . '"' . ($filter->websiteId === (int) $w['id'] ? ' selected' : '') . '>' . e($w['name']) . '</option>';
}
$siteSelect .= '</select></div>';
$exportQuery = ['period' => $period, 'from' => $from, 'to' => $to, 'website' => $filter->websiteId];
?>
<div class="page-head">
    <h1>Reports</h1>
    <a class="btn" href="<?= e($ctx->url('/reports/export', $exportQuery)) ?>">Download CSV</a>
</div>

<?= $view->partial('partials/period-form', ['period' => $period, 'from' => $from, 'to' => $to, 'action' => '/reports', 'extra' => [$siteSelect]]) ?>

<section class="panel" aria-labelledby="matrix-h">
    <h2 id="matrix-h" class="h3">Inquiries by website and type</h2>
    <?php if (!$rows): ?>
        <p class="muted">No websites are available to your account.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table report-table">
            <thead>
            <tr>
                <th scope="col">Website</th>
                <?php foreach (Presenter::TYPE_LABELS as $label): ?><th scope="col" class="num"><?= e($label) ?></th><?php endforeach; ?>
                <th scope="col" class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <th scope="row"><?= e($r['name']) ?> <span class="sub"><?= e($r['domain']) ?></span></th>
                    <?php foreach ($r['counts'] as $type => $n): ?>
                        <td class="num" data-label="<?= e(Presenter::TYPE_LABELS[$type]) ?>">
                            <?php if ($n > 0): ?><a href="<?= e($ctx->url('/inbox', $range + ['website' => $r['id'], 'type' => $type])) ?>"><?= e(number_format($n)) ?></a><?php else: ?><span class="muted">0</span><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="num" data-label="Total"><a href="<?= e($ctx->url('/inbox', $range + ['website' => $r['id']])) ?>"><strong><?= e(number_format($r['total'])) ?></strong></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row">All<?= $filter->websiteId ? ' (selected website)' : ' websites' ?></th>
                <?php foreach ($totals as $type => $n): ?>
                    <td class="num" data-label="<?= e(Presenter::TYPE_LABELS[$type]) ?>"><a href="<?= e($ctx->url('/inbox', $range + ['type' => $type, 'website' => $filter->websiteId])) ?>"><?= e(number_format($n)) ?></a></td>
                <?php endforeach; ?>
                <td class="num" data-label="Total"><a href="<?= e($ctx->url('/inbox', $range + ['website' => $filter->websiteId])) ?>"><strong><?= e(number_format($grand)) ?></strong></a></td>
            </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="panel">
    <?= $view->partial('partials/daily-chart', ['daily' => $dailyByType, 'baseQuery' => array_filter(['website' => $filter->websiteId]), 'ctx' => $ctx]) ?>
</section>
