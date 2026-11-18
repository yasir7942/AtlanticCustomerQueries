<?php
$me = $ctx->me();
$roleLabels = ['developer' => 'Developer', 'director' => 'Director', 'user' => 'User'];
$invalid = fn (array $errs, string $k) => !empty($errs[$k]) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-error"' : '';
$now = new DateTimeImmutable('now', \App\Support\Time::zone($me['timezone']));
?>
<div class="page-head">
    <div>
        <h1>Your profile</h1>
        <p class="muted"><?= e($me['email']) ?> · <?= e($roleLabels[$me['role']]) ?></p>
    </div>
</div>
<div class="grid-2">
    <section class="panel" aria-labelledby="prof-h">
        <h2 id="prof-h" class="h3">Details and time zone</h2>
        <form method="post" action="<?= e($ctx->url('/profile')) ?>" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" required maxlength="120" value="<?= e($me['name']) ?>"<?= $invalid($errors, 'name') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'name']) ?>
            </div>
            <div class="field">
                <label for="timezone">Time zone</label>
                <?= $view->partial('partials/timezone-select', ['selected' => $me['timezone'], 'name' => 'timezone', 'errors' => $errors]) ?>
                <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'timezone']) ?>
                <p class="hint">Your local time now: <?= e($now->format('D d M Y, H:i')) ?>. Dates, filters and daily totals use this zone.</p>
            </div>
            <button type="submit" class="btn">Save profile</button>
        </form>
    </section>
    <section class="panel" aria-labelledby="pw-h">
        <h2 id="pw-h" class="h3">Change password</h2>
        <form method="post" action="<?= e($ctx->url('/profile/password')) ?>" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password" maxlength="200"<?= $invalid($pwErrors, 'current_password') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $pwErrors, 'name' => 'current_password']) ?>
            </div>
            <div class="field">
                <label for="password">New password <span class="hint">(12–72 characters)</span></label>
                <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password" maxlength="200"<?= $invalid($pwErrors, 'password') ?>>
                <?= $view->partial('partials/field-error', ['errors' => $pwErrors, 'name' => 'password']) ?>
            </div>
            <div class="field">
                <label for="password_confirm">Repeat new password</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" maxlength="200">
            </div>
            <button type="submit" class="btn">Change password</button>
            <p class="hint">Changing your password signs out your other sessions.</p>
        </form>
    </section>
</div>
