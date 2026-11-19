<?php
use App\Domain\Submissions\Presenter;
use App\Support\Time;

/** @var \App\Domain\Submissions\InquiryFilter $filter */
$tz = $ctx->timezone();
$query = $filter->toQuery();
$siteNames = array_column($websites, 'name', 'id');
$chips = [];
if ($filter->q !== '') { $chips[] = ['Search', '“' . $filter->q . '”', 'q']; }
if ($filter->websiteId !== null) { $chips[] = ['Website', $siteNames[$filter->websiteId] ?? 'Unavailable website', 'website']; }
if ($filter->formType !== null) { $chips[] = ['Type', Presenter::TYPE_LABELS[$filter->formType], 'type']; }
if ($filter->status !== null) { $chips[] = ['Status', Presenter::STATUS_LABELS[$filter->status], 'status']; }
if ($filter->from !== null) { $chips[] = ['From', date_create($filter->from)->format('d M Y'), 'from']; }
if ($filter->to !== null) { $chips[] = ['To', date_create($filter->to)->format('d M Y'), 'to']; }
if (!$filter->includeTests) { $chips[] = ['Tests', 'Hidden', 'tests']; }
?>
<div class="page-head">
    <h1>Inbox</h1>
    <div class="head-actions">
        <?php if ($canDelete && $testCount > 0): ?>
            <form method="post" action="<?= e($ctx->url('/inbox/delete-tests')) ?>"
                  data-confirm="Permanently delete <?= e($testCount) ?> test inquir<?= $testCount === 1 ? 'y' : 'ies' ?><?= $filter->websiteId !== null ? ' from ' . e($siteNames[$filter->websiteId] ?? 'this website') : '' ?>? This cannot be undone.">
                <?= $ctx->csrf->field() ?>
                <?php if ($filter->websiteId !== null): ?><input type="hidden" name="website" value="<?= e($filter->websiteId) ?>"><?php endif; ?>
                <button type="submit" class="btn btn-danger" title="Inquiries sent with &quot;test&quot;: true, such as connection tests">Delete test inquiries (<?= e(number_format($testCount)) ?>)</button>
            </form>
        <?php endif; ?>
        <?php if ($total > 0): ?>
            <a class="btn" href="<?= e($ctx->url('/inbox/export', $query)) ?>">Download CSV</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e($ctx->url('/inbox')) ?>" class="filters" role="search" aria-label="Filter inquiries">
    <div class="field field-grow">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($filter->q) ?>" placeholder="Name, company, email, phone, message, product…" maxlength="200">
    </div>
    <div class="field">
        <label for="website">Website</label>
        <select id="website" name="website">
            <option value="">All websites</option>
            <?php foreach ($websites as $w): ?>
                <option value="<?= e($w['id']) ?>"<?= $filter->websiteId === (int) $w['id'] ? ' selected' : '' ?>><?= e($w['name']) ?><?= $w['status'] === 'archived' ? ' (archived)' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="type">Form type</label>
        <select id="type" name="type">
            <option value="">All types</option>
            <?php foreach (Presenter::TYPE_LABELS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $filter->formType === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <?php foreach (Presenter::STATUS_LABELS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $filter->status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($filter->from ?? '') ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($filter->to ?? '') ?>">
    </div>
    <div class="filters-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($filter->isActive()): ?>
            <a href="<?= e($ctx->url('/inbox')) ?>" class="btn btn-ghost">Clear all</a>
        <?php endif; ?>
    </div>
</form>

<div class="result-bar" aria-live="polite">
    <p><strong><?= e(number_format($total)) ?></strong> inquir<?= $total === 1 ? 'y' : 'ies' ?><?= $filter->isActive() ? ' match these filters' : '' ?> · dates in <?= e($tz) ?></p>
    <?php if ($chips): ?>
        <ul class="chips" aria-label="Active filters">
            <?php foreach ($chips as [$label, $value, $key]): $without = $query; unset($without[$key]); ?>
                <li class="chip"><span><?= e($label) ?>: <bdi><?= e($value) ?></bdi></span>
                    <a href="<?= e($ctx->url('/inbox', $without)) ?>" aria-label="Remove filter <?= e($label) ?>">×</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($noScope): ?>
    <div class="empty">
        <h2 class="h3">No websites assigned yet</h2>
        <p>Your account only shows inquiries from websites assigned to you. Ask a Director or Developer to assign one.</p>
    </div>
<?php elseif (!$rows): ?>
    <div class="empty">
        <h2 class="h3"><?= $filter->isActive() ? 'No inquiries match these filters' : 'No inquiries yet' ?></h2>
        <p><?= $filter->isActive() ? 'Try a wider date range or remove a filter.' : 'Inquiries appear here as soon as a connected website sends them.' ?></p>
    </div>
<?php else: ?>
    <?php if ($canDelete): ?>
        <form id="bulk-delete" method="post" action="<?= e($ctx->url('/inbox/delete')) ?>" class="bulk-bar"
              data-confirm="Permanently delete the selected inquiries? This cannot be undone.">
            <?= $ctx->csrf->field() ?>
            <button type="submit" class="btn btn-danger btn-small" data-bulk-button disabled><span>Delete selected (<span data-bulk-count>0</span>)</span></button>
            <span class="muted">Tick inquiries you sent while testing, then delete them. Developers only.</span>
        </form>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="table inbox-table">
            <thead>
            <tr>
                <?php if ($canDelete): ?>
                    <th scope="col" class="col-check"><input type="checkbox" data-check-all aria-label="Select all inquiries on this page"></th>
                <?php endif; ?>
                <th scope="col">Received</th>
                <th scope="col">Website</th>
                <th scope="col">Type</th>
                <th scope="col">Customer</th>
                <th scope="col">Request</th>
                <th scope="col">Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $href = $ctx->url('/inbox/' . $r['id']); $summary = Presenter::summary($r); ?>
                <tr class="<?= $r['status'] === 'new' ? 'is-new' : '' ?>">
                    <?php if ($canDelete): ?>
                        <td data-label="Select" class="col-check"><input type="checkbox" name="ids[]" value="<?= e($r['id']) ?>" form="bulk-delete" data-bulk aria-label="Select inquiry #<?= e($r['id']) ?>"></td>
                    <?php endif; ?>
                    <td data-label="Received" class="nowrap"><a href="<?= e($href) ?>" class="row-link"><time datetime="<?= e(Time::iso($r['received_at'])) ?>"><?= e(Time::local($r['received_at'], $tz)) ?></time></a></td>
                    <td data-label="Website"><?= e($r['website_name']) ?></td>
                    <td data-label="Type"><?= \App\Support\Icons::typeBadge($r['form_type'], Presenter::TYPE_LABELS[$r['form_type']]) ?><?php if ($r['is_test']): ?> <span class="tag">Test</span><?php endif; ?></td>
                    <td data-label="Customer"><bdi dir="<?= e(Presenter::dir(Presenter::customer($r))) ?>"><?= e(Presenter::customer($r)) ?></bdi>
                        <?php if ($r['email'] !== ''): ?><span class="sub"><?= e($r['email']) ?></span><?php endif; ?></td>
                    <td data-label="Request" class="summary"><span dir="<?= e(Presenter::dir($summary)) ?>"><?= e($summary) ?></span></td>
                    <td data-label="Status"><span class="status status-<?= e($r['status']) ?>"><?= e(Presenter::STATUS_LABELS[$r['status']]) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/inbox', 'query' => $query, 'ctx' => $ctx]) ?>
<?php endif; ?>
