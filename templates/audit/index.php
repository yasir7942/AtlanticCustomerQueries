<?php use App\Support\Time; $tz = $ctx->timezone(); ?>
<div class="page-head">
    <h1>Audit log</h1>
</div>
<p class="muted">Sign-ins, account and permission changes, website changes and key lifecycle events. Times in <?= e($tz) ?> (stored in UTC).</p>

<form method="get" action="<?= e($ctx->url('/audit')) ?>" class="toolbar">
    <div class="field field-inline">
        <label for="action">Action</label>
        <select id="action" name="action">
            <option value="">All actions</option>
            <?php foreach (['auth.', 'user.', 'website.', 'credential.', 'inquiry.', 'access.'] as $prefix): ?>
                <option value="<?= e($prefix) ?>"<?= $action === $prefix ? ' selected' : '' ?>><?= e(ucfirst(rtrim($prefix, '.'))) ?> (all)</option>
            <?php endforeach; ?>
            <?php foreach ($actions as $a): ?>
                <option value="<?= e($a) ?>"<?= $action === $a ? ' selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Apply</button>
    <p class="toolbar-note"><?= e(number_format($total)) ?> events</p>
</form>

<?php if (!$rows): ?>
    <div class="empty"><h2 class="h3">No events</h2></div>
<?php else: ?>
<div class="table-wrap">
    <table class="table table-compact">
        <thead><tr><th scope="col">Time</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Outcome</th><th scope="col">Details</th><th scope="col">IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $details = $r['details'] ? json_decode($r['details'], true) : null; ?>
            <tr>
                <td data-label="Time" class="nowrap"><time datetime="<?= e(Time::iso($r['occurred_at'])) ?>"><?= e(Time::local($r['occurred_at'], $tz, 'd M Y H:i:s')) ?></time></td>
                <td data-label="Actor"><?= $r['actor_label'] !== '' ? e($r['actor_label']) : '<span class="muted">system / anonymous</span>' ?></td>
                <td data-label="Action" class="mono"><?= e($r['action']) ?></td>
                <td data-label="Target"><?= e($r['target_type']) ?><?= $r['target_id'] !== null ? ' #' . e($r['target_id']) : '' ?> <span class="sub"><?= e($r['target_label']) ?></span></td>
                <td data-label="Outcome"><span class="status <?= $r['outcome'] === 'success' ? 'status-ok' : ($r['outcome'] === 'denied' ? 'status-warn' : 'status-error') ?>"><?= e(ucfirst($r['outcome'])) ?></span></td>
                <td data-label="Details" class="mono small break"><?= is_array($details) ? e(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '' ?></td>
                <td data-label="IP"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/audit', 'query' => array_filter(['action' => $action]), 'ctx' => $ctx]) ?>
<?php endif; ?>
