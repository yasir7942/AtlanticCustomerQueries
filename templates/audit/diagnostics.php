<div class="page-head">
    <h1>Connection diagnostics</h1>
</div>
<p class="muted">Technical API request metadata (no bodies or keys). Kept for <?= e($ctx->config->get('diagnostics_retention_days')) ?> days. Times in <?= e($ctx->timezone()) ?>.</p>

<form method="get" action="<?= e($ctx->url('/diagnostics')) ?>" class="toolbar">
    <div class="field field-inline">
        <label for="website">Website</label>
        <select id="website" name="website">
            <option value="">All websites</option>
            <?php foreach ($websites as $w): ?>
                <option value="<?= e($w['id']) ?>"<?= (string) $w['id'] === $website ? ' selected' : '' ?>><?= e($w['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="checkbox">
        <input type="checkbox" id="errors" name="errors" value="1"<?= $errorsOnly ? ' checked' : '' ?>>
        <label for="errors">Errors only</label>
    </div>
    <button type="submit" class="btn">Apply</button>
</form>

<?php if ($summary): ?>
<section class="panel" aria-labelledby="sum-h">
    <h2 id="sum-h" class="h3">Last 24 hours</h2>
    <ul class="chips">
        <?php foreach ($summary as $s): ?>
            <li class="chip"><span><?= e($s['http_status']) ?> <?= e($s['result_code']) ?>: <strong><?= e($s['n']) ?></strong></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty"><h2 class="h3">No API requests recorded</h2><p>Requests appear here as soon as a website calls the endpoint.</p></div>
<?php else: ?>
    <?= $view->partial('audit/diagnostics-table', ['rows' => $rows, 'tz' => $ctx->timezone(), 'showSite' => true]) ?>
<?php endif; ?>
