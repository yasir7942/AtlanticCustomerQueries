<?php
/** @var string $period @var string $from @var string $to @var string $action @var array $extra */
$extra ??= [];
?>
<form method="get" action="<?= e($ctx->url($action)) ?>" class="toolbar" aria-label="Period">
    <div class="field field-inline">
        <label for="period">Period</label>
        <select id="period" name="period" data-period-select>
            <?php foreach (\App\Controllers\DashboardController::PERIODS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $k === $period ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field-inline" data-custom-range>
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field field-inline" data-custom-range>
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <?php foreach ($extra as $html) { echo $html; } ?>
    <button type="submit" class="btn">Apply</button>
    <p class="toolbar-note"><?= e(date_create($from)->format('d M Y')) ?> – <?= e(date_create($to)->format('d M Y')) ?> · <?= e($ctx->timezone()) ?></p>
</form>
