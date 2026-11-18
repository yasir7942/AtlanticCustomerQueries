<?php
/** Password section for create-account and reset-password forms. */
$mode = $old['password_mode'] ?? 'temporary';
$prefix ??= 'pw';
?>
<fieldset class="fieldset">
    <legend>Password</legend>
    <div class="radio">
        <input type="radio" id="<?= e($prefix) ?>-temp" name="password_mode" value="temporary"<?= $mode === 'temporary' ? ' checked' : '' ?> data-toggle-target="<?= e($prefix) ?>-set-fields" data-toggle-show="0">
        <label for="<?= e($prefix) ?>-temp"><strong>Generate a temporary password</strong> — shown once; the person must choose a new password at first sign-in.</label>
    </div>
    <div class="radio">
        <input type="radio" id="<?= e($prefix) ?>-set" name="password_mode" value="set"<?= $mode === 'set' ? ' checked' : '' ?> data-toggle-target="<?= e($prefix) ?>-set-fields" data-toggle-show="1">
        <label for="<?= e($prefix) ?>-set"><strong>Set a password now</strong></label>
    </div>
    <div id="<?= e($prefix) ?>-set-fields" class="subfields">
        <div class="field">
            <label for="<?= e($prefix) ?>-password">New password <span class="hint">(at least 12 characters)</span></label>
            <input type="password" id="<?= e($prefix) ?>-password" name="password" autocomplete="new-password" maxlength="200">
        </div>
        <div class="field">
            <label for="<?= e($prefix) ?>-password2">Repeat password</label>
            <input type="password" id="<?= e($prefix) ?>-password2" name="password_confirm" autocomplete="new-password" maxlength="200">
        </div>
        <div class="checkbox">
            <input type="checkbox" id="<?= e($prefix) ?>-require" name="require_change" value="1"<?= !empty($old['require_change']) ? ' checked' : '' ?>>
            <label for="<?= e($prefix) ?>-require">Require a password change at next sign-in</label>
        </div>
    </div>
    <?= $view->partial('partials/field-error', ['errors' => $errors, 'name' => 'password']) ?>
</fieldset>
