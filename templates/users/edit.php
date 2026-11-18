<?php
use App\Support\Time;

$tz = $ctx->timezone();
$roleLabels = ['developer' => 'Developer', 'director' => 'Director', 'user' => 'User'];
$invalid = fn (string $k) => !empty($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-error"' : '';
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/users')) ?>">← Users</a></p>
        <h1><?= e($target['name']) ?> <span class="status <?= $target['status'] === 'active' ? 'status-ok' : 'status-off' ?>"><?= e(ucfirst($target['status'])) ?></span></h1>
        <p class="muted"><?= e($target['email']) ?> · <?= e($roleLabels[$target['role']]) ?> · time zone <?= e($target['timezone']) ?>
            · last sign-in <?= $target['last_login_at'] ? e(Time::local($target['last_login_at'], $tz)) : 'never' ?></p>
    </div>
</div>

<?php if ($tempPassword !== null): ?>
    <section class="secret-box" role="alert" aria-labelledby="tmp-h">
        <h2 id="tmp-h" class="h3">Temporary password</h2>
        <p>Give this to <?= e($target['name']) ?> through a private channel. It is shown only once, and they must replace it at first sign-in.</p>
        <div class="copy-row">
            <input type="text" readonly value="<?= e($tempPassword) ?>" id="temp-pw" class="mono" aria-label="Temporary password" data-select-on-focus>
            <button type="button" class="btn btn-primary" data-copy="#temp-pw">Copy</button>
        </div>
    </section>
<?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<?php if (!$canManage): ?>
    <div class="alert alert-info">Your role cannot modify <?= e($roleLabels[$target['role']]) ?> accounts.</div>
<?php else: ?>
<div class="grid-2">
    <section class="panel" aria-labelledby="acct-h">
        <h2 id="acct-h" class="h3">Account</h2>
        <form method="post" action="<?= e($ctx->url('/users/' . $target['id'])) ?>" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" required maxlength="120" value="<?= e($target['name']) ?>"<?= $invalid('name') ?>>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required maxlength="190" value="<?= e($target['email']) ?>"<?= $invalid('email') ?>>
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role"<?= $invalid('role') ?>>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r) ?>"<?= $target['role'] === $r ? ' selected' : '' ?>><?= e($roleLabels[$r]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="status" value="<?= e($target['status']) ?>">
                    <p class="small muted" id="status">You cannot deactivate your own account.</p>
                <?php else: ?>
                    <select id="status" name="status">
                        <option value="active"<?= $target['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="inactive"<?= $target['status'] === 'inactive' ? ' selected' : '' ?>>Inactive (cannot sign in; signed out now)</option>
                    </select>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn">Save account</button>
        </form>
    </section>

    <section class="panel" aria-labelledby="sites-h">
        <h2 id="sites-h" class="h3">Website access</h2>
        <?php if ($target['role'] !== 'user'): ?>
            <p><?= e($roleLabels[$target['role']]) ?>s see every website. Assignments apply to Users only.</p>
        <?php elseif (!$websites): ?>
            <p class="muted">No websites are registered yet.</p>
        <?php else: ?>
            <form method="post" action="<?= e($ctx->url('/users/' . $target['id'] . '/websites')) ?>" data-busy>
                <?= $ctx->csrf->field() ?>
                <fieldset class="fieldset">
                    <legend>Websites this user can see</legend>
                    <?php foreach ($websites as $w): ?>
                        <div class="checkbox">
                            <input type="checkbox" id="w-<?= e($w['id']) ?>" name="websites[]" value="<?= e($w['id']) ?>"<?= in_array((int) $w['id'], $assigned, true) ? ' checked' : '' ?>>
                            <label for="w-<?= e($w['id']) ?>"><?= e($w['name']) ?> <span class="sub"><?= e($w['domain']) ?><?= $w['status'] !== 'active' ? ' · ' . e($w['status']) : '' ?></span></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="btn">Save website access</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php if (!$isSelf): ?>
<section class="panel form-narrow" aria-labelledby="reset-h">
    <h2 id="reset-h" class="h3">Reset password</h2>
    <p class="small">Resetting signs this person out of every session.</p>
    <form method="post" action="<?= e($ctx->url('/users/' . $target['id'] . '/password')) ?>" data-confirm="Reset this password and sign the account out everywhere?">
        <?= $ctx->csrf->field() ?>
        <?= $view->partial('partials/password-choice', ['old' => [], 'errors' => $errors, 'prefix' => 'reset']) ?>
        <button type="submit" class="btn">Reset password</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>
