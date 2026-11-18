<?php
use App\Domain\WebsiteService;
use App\Support\Time;

$tz = $ctx->timezone();
$dev = $ctx->can('websites.connection');
$v = fn (string $k) => $old[$k] ?? $site[$k] ?? '';
$invalid = fn (string $k) => !empty($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-error"' : '';
$total = (int) ($stats['total'] ?? 0);
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/websites')) ?>">← Websites</a></p>
        <h1><?= e($site['name']) ?> <span class="status status-site-<?= e($site['status']) ?>"><?= e(ucfirst($site['status'])) ?></span></h1>
        <p class="muted"><?= e($site['domain']) ?> · <?= e(WebsiteService::PLATFORMS[$site['platform']] ?? $site['platform']) ?>
            · <a href="<?= e($ctx->url('/inbox', ['website' => $site['id']])) ?>"><?= e(number_format($total)) ?> inquiries</a>
            <?= $stats['last_at'] ? ' · last ' . e(Time::local($stats['last_at'], $tz)) : '' ?></p>
    </div>
    <?php if ($dev && $site['status'] !== 'archived'): ?>
        <a class="btn btn-primary" href="<?= e($ctx->url('/websites/' . $site['id'] . '/connect')) ?>">API Connect</a>
    <?php endif; ?>
</div>
<?php if ($errors): ?><div class="alert alert-error" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
<?php if ($site['status'] === 'archived'): ?>
    <div class="alert alert-info">Archived <?= e(Time::local($site['archived_at'], $tz)) ?>. Its inquiries stay readable; its keys are revoked and the API rejects it.</div>
<?php endif; ?>

<div class="grid-2">
    <section class="panel" aria-labelledby="biz-h">
        <h2 id="biz-h" class="h3">Business details</h2>
        <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/business')) ?>" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="name">Display name</label>
                <input id="name" name="name" required maxlength="120" value="<?= e($v('name')) ?>"<?= $invalid('name') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'name']) ?>
            </div>
            <div class="field">
                <label for="business_contact">Business contact</label>
                <input id="business_contact" name="business_contact" maxlength="120" value="<?= e($v('business_contact')) ?>">
            </div>
            <div class="field">
                <label for="business_email">Business email</label>
                <input id="business_email" name="business_email" type="email" maxlength="190" value="<?= e($v('business_email')) ?>"<?= $invalid('business_email') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'business_email']) ?>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000"><?= e($v('notes')) ?></textarea>
            </div>
            <button type="submit" class="btn">Save business details</button>
        </form>
    </section>

    <section class="panel" aria-labelledby="access-h">
        <h2 id="access-h" class="h3">Users with access</h2>
        <p class="muted small">Directors and Developers see every website. Users below were assigned this website.</p>
        <?php if (!$assigned): ?>
            <p class="muted">No Users are assigned.</p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($assigned as $u): ?>
                    <li><a href="<?= e($ctx->url('/users/' . $u['id'])) ?>"><?= e($u['name']) ?></a> <span class="sub"><?= e($u['email']) ?><?= $u['status'] !== 'active' ? ' · inactive' : '' ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="small">Change assignments from each User's account page.</p>
    </section>
</div>

<?php if ($dev): ?>
<div class="grid-2">
    <section class="panel" aria-labelledby="conn-h">
        <h2 id="conn-h" class="h3">Connection settings <span class="tag">Developer</span></h2>
        <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/connection')) ?>" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="domain">Domain</label>
                <input id="domain" name="domain" required maxlength="190" value="<?= e($v('domain')) ?>" spellcheck="false"<?= $invalid('domain') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'domain']) ?>
            </div>
            <div class="field">
                <label for="platform">Platform</label>
                <select id="platform" name="platform">
                    <?php foreach (WebsiteService::PLATFORMS as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= $v('platform') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="rate_limit_per_minute">API limit per minute</label>
                <input id="rate_limit_per_minute" name="rate_limit_per_minute" type="number" min="1" max="6000" value="<?= e($v('rate_limit_per_minute')) ?>"<?= $invalid('rate_limit_per_minute') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'rate_limit_per_minute']) ?>
            </div>
            <button type="submit" class="btn">Save connection settings</button>
        </form>
    </section>

    <section class="panel danger-zone" aria-labelledby="life-h">
        <h2 id="life-h" class="h3">Lifecycle <span class="tag">Developer</span></h2>
        <?php if ($site['status'] !== 'archived'): ?>
            <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/status')) ?>" class="stack">
                <?= $ctx->csrf->field() ?>
                <?php if ($site['status'] === 'active'): ?>
                    <input type="hidden" name="status" value="inactive">
                    <p class="small">Deactivating keeps keys and history but rejects new submissions with <code>403 website_inactive</code>.</p>
                    <button type="submit" class="btn">Deactivate website</button>
                <?php else: ?>
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn">Activate website</button>
                <?php endif; ?>
            </form>
            <hr>
            <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/delete')) ?>" class="stack" data-confirm="<?= $total > 0 ? 'Archive this website and revoke all of its keys?' : 'Delete this website permanently?' ?>">
                <?= $ctx->csrf->field() ?>
                <?php if ($total > 0): ?>
                    <p class="small">This website has <?= e(number_format($total)) ?> inquiries. Removing it <strong>archives</strong> it: history stays readable, every key is revoked and the API rejects it.</p>
                    <button type="submit" class="btn btn-danger">Archive website</button>
                <?php else: ?>
                    <p class="small">No inquiries yet, so the website, its forms and keys are deleted.</p>
                    <button type="submit" class="btn btn-danger">Delete website</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <?php if ($total > 0): ?>
            <hr>
            <details>
                <summary>Permanently delete this website and its <?= e(number_format($total)) ?> inquiries</summary>
                <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/delete')) ?>" class="stack" data-confirm="This permanently deletes every inquiry from this website. Continue?">
                    <?= $ctx->csrf->field() ?>
                    <p class="small">This cannot be undone. Take a database backup first. Type the domain <code><?= e($site['domain']) ?></code> to confirm.</p>
                    <div class="field">
                        <label for="confirm_domain">Domain</label>
                        <input id="confirm_domain" name="confirm_domain" required autocomplete="off" spellcheck="false">
                    </div>
                    <button type="submit" class="btn btn-danger">Delete permanently</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
