<?php if (!empty($flash)): ?>
    <?php $type = in_array($flash['type'] ?? '', ['success', 'error', 'info', 'warning'], true) ? $flash['type'] : 'info'; ?>
    <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>
