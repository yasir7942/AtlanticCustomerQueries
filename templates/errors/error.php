<section class="card card-narrow" aria-labelledby="err-title">
    <p class="muted">Error <?= e($status) ?></p>
    <h1 id="err-title"><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <?php if (!empty($detail)): ?>
        <pre class="debug"><?= e($detail) ?></pre>
    <?php endif; ?>
    <p><a class="btn" href="<?= e($home) ?>">Go to the dashboard</a></p>
</section>
