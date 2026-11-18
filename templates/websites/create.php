<?php
use App\Domain\WebsiteService;

$f = fn (string $k, string $d = '') => $old[$k] ?? $d;
$invalid = fn (string $k) => !empty($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-error"' : '';
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/websites')) ?>">← Websites</a></p>
        <h1>Register a website</h1>
    </div>
</div>
<?php if ($errors): ?><div class="alert alert-error" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
<form method="post" action="<?= e($ctx->url('/websites')) ?>" class="panel form-grid" data-busy>
    <?= $ctx->csrf->field() ?>
    <div class="field">
        <label for="name">Display name</label>
        <input id="name" name="name" required maxlength="120" value="<?= e($f('name')) ?>"<?= $invalid('name') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'name']) ?>
    </div>
    <div class="field">
        <label for="domain">Domain <span class="hint">e.g. example.com</span></label>
        <input id="domain" name="domain" required maxlength="190" value="<?= e($f('domain')) ?>" autocapitalize="off" spellcheck="false"<?= $invalid('domain') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'domain']) ?>
    </div>
    <div class="field">
        <label for="platform">Platform</label>
        <select id="platform" name="platform" required<?= $invalid('platform') ?>>
            <option value="">Choose…</option>
            <?php foreach (WebsiteService::PLATFORMS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $f('platform') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'platform']) ?>
    </div>
    <div class="field">
        <label for="rate_limit_per_minute">API limit per minute</label>
        <input id="rate_limit_per_minute" name="rate_limit_per_minute" type="number" min="1" max="6000" value="<?= e($f('rate_limit_per_minute', '60')) ?>"<?= $invalid('rate_limit_per_minute') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'rate_limit_per_minute']) ?>
    </div>
    <div class="field">
        <label for="business_contact">Business contact <span class="hint">optional</span></label>
        <input id="business_contact" name="business_contact" maxlength="120" value="<?= e($f('business_contact')) ?>">
    </div>
    <div class="field">
        <label for="business_email">Business email <span class="hint">optional</span></label>
        <input id="business_email" name="business_email" type="email" maxlength="190" value="<?= e($f('business_email')) ?>"<?= $invalid('business_email') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'business_email']) ?>
    </div>
    <div class="field field-full">
        <label for="notes">Notes <span class="hint">optional</span></label>
        <textarea id="notes" name="notes" rows="3" maxlength="5000"><?= e($f('notes')) ?></textarea>
    </div>
    <div class="form-actions field-full">
        <button type="submit" class="btn btn-primary">Register website</button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/websites')) ?>">Cancel</a>
    </div>
</form>
