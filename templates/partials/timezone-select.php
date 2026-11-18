<?php
/** @var string $selected @var string $name */
$name ??= 'timezone';
$common = ['Asia/Dubai', 'Asia/Karachi', 'Asia/Riyadh', 'Asia/Qatar', 'Asia/Kolkata', 'Europe/London', 'UTC'];
?>
<select id="<?= e($name) ?>" name="<?= e($name) ?>" required<?= !empty($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '' ?>>
    <optgroup label="Common">
        <?php foreach ($common as $z): ?>
            <option value="<?= e($z) ?>"<?= $z === $selected ? ' selected' : '' ?>><?= e($z) ?></option>
        <?php endforeach; ?>
    </optgroup>
    <?php foreach (\App\Support\Time::groupedZones() as $region => $zones): ?>
        <optgroup label="<?= e($region) ?>">
            <?php foreach ($zones as $z): if (in_array($z, $common, true)) { continue; } ?>
                <option value="<?= e($z) ?>"<?= $z === $selected ? ' selected' : '' ?>><?= e($z) ?></option>
            <?php endforeach; ?>
        </optgroup>
    <?php endforeach; ?>
</select>
