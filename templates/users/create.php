<?php
$roleLabels = ['developer' => 'Developer', 'director' => 'Director', 'user' => 'User'];
$roleHelp = [
    'developer' => 'Everything, including website registration, API keys and diagnostics.',
    'director' => 'All websites, reports and audit log; manages Directors and Users.',
    'user' => 'Only websites assigned to them.',
];
$invalid = fn (string $k) => !empty($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-error"' : '';
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/users')) ?>">← Users</a></p>
        <h1>Create an account</h1>
    </div>
</div>
<?php if ($errors): ?><div class="alert alert-error" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
<form method="post" action="<?= e($ctx->url('/users')) ?>" class="panel form-narrow" data-busy>
    <?= $ctx->csrf->field() ?>
    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" required maxlength="120" value="<?= e($old['name'] ?? '') ?>"<?= $invalid('name') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'name']) ?>
    </div>
    <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>" autocomplete="off"<?= $invalid('email') ?>>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'email']) ?>
    </div>
    <fieldset class="fieldset">
        <legend>Role</legend>
        <?php foreach ($roles as $r): ?>
            <div class="radio">
                <input type="radio" id="role-<?= e($r) ?>" name="role" value="<?= e($r) ?>"<?= ($old['role'] ?? 'user') === $r ? ' checked' : '' ?>>
                <label for="role-<?= e($r) ?>"><strong><?= e($roleLabels[$r]) ?></strong> — <?= e($roleHelp[$r]) ?></label>
            </div>
        <?php endforeach; ?>
    </fieldset>
    <div class="field">
        <label for="timezone">Time zone</label>
        <?= $view->partial('partials/timezone-select', ['selected' => $old['timezone'] ?? 'Asia/Dubai', 'name' => 'timezone', 'errors' => $errors]) ?>
        <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'timezone']) ?>
    </div>
    <?= $view->partial('partials/password-choice', ['old' => $old, 'errors' => $errors, 'prefix' => 'new']) ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create account</button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/users')) ?>">Cancel</a>
    </div>
</form>
