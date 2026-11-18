<section class="card card-narrow" aria-labelledby="pw-title">
    <h1 id="pw-title"><?= $forced ? 'Choose a new password' : 'Change password' ?></h1>
    <?php if ($forced): ?>
        <p>You signed in with a temporary password. Choose your own password to continue.</p>
    <?php endif; ?>
    <form method="post" action="<?= e($ctx->url('/password/change')) ?>" data-busy>
        <?= $ctx->csrf->field() ?>
        <div class="field">
            <label for="current_password"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required maxlength="200"<?= !empty($errors['current_password']) ? ' aria-invalid="true" aria-describedby="current_password-error"' : '' ?>>
            <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'current_password']) ?>
        </div>
        <div class="field">
            <label for="password">New password <span class="hint">(12–72 characters)</span></label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="12" maxlength="200"<?= !empty($errors['password']) ? ' aria-invalid="true" aria-describedby="password-error"' : '' ?>>
            <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'password']) ?>
        </div>
        <div class="field">
            <label for="password_confirm">Repeat new password</label>
            <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required maxlength="200">
        </div>
        <button type="submit" class="btn btn-primary">Save password</button>
    </form>
    <?php if ($forced): ?>
        <form method="post" action="<?= e($ctx->url('/logout')) ?>" class="inline-form">
            <?= $ctx->csrf->field() ?>
            <button type="submit" class="btn btn-ghost">Sign out instead</button>
        </form>
    <?php endif; ?>
</section>
