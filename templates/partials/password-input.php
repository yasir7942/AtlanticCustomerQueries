<?php
/**
 * Password input with a show/hide (eye) button. The button is hidden until app.js enables it,
 * so without JavaScript the field still works as a normal password field.
 *
 * @var string $id @var string $name @var string $autocomplete
 */
$attrs = $attrs ?? '';
?>
<div class="password-wrap">
    <input type="password" id="<?= e($id) ?>" name="<?= e($name) ?>" autocomplete="<?= e($autocomplete) ?>" required maxlength="200"<?= $attrs ?>>
    <button type="button" class="password-toggle" data-password-toggle="<?= e($id) ?>" aria-controls="<?= e($id) ?>" aria-label="Show password" aria-pressed="false" title="Show password" hidden>
        <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
            <circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
        </svg>
        <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3.2 3.2 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
</div>
