<section class="card card-narrow" aria-labelledby="login-title">
    <h1 id="login-title">Sign in</h1>
    <?= $view->partial('partials/flash', ['flash' => $flash ?? null]) ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e($ctx->url('/login')) ?>" data-busy>
        <?= $ctx->csrf->field() ?>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email ?? '') ?>" autocomplete="username" required autofocus maxlength="190">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <?= $view->partial('partials/password-input', ['id' => 'password', 'name' => 'password', 'autocomplete' => 'current-password']) ?>
        </div>
        <?php $rememberDays = $ctx->auth->rememberDays(); if ($rememberDays > 0): ?>
            <div class="checkbox remember">
                <input type="checkbox" id="remember" name="remember" value="1"<?= ($remember ?? true) ? ' checked' : '' ?>>
                <label for="remember">Remember me <span class="hint">— stay signed in on this device for <?= e($rememberDays) ?> days</span></label>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="muted small">Accounts are created by a Director or Developer. Ask them to reset your password if you cannot sign in.</p>
</section>
