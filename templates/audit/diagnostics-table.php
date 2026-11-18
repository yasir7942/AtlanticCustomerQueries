<?php use App\Support\Time; ?>
<div class="table-wrap">
    <table class="table table-compact">
        <thead><tr>
            <th scope="col">Time</th>
            <?php if ($showSite): ?><th scope="col">Website</th><?php endif; ?>
            <th scope="col">Result</th><th scope="col">Key id</th><th scope="col">Submission ID</th>
            <th scope="col">Sender IP</th><th scope="col" class="num">Bytes</th><th scope="col" class="num">ms</th><th scope="col">Invalid fields</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $l): $ok = $l['http_status'] < 300; ?>
            <tr>
                <td data-label="Time" class="nowrap"><?= e(Time::local($l['occurred_at'], $tz, 'd M H:i:s')) ?></td>
                <?php if ($showSite): ?><td data-label="Website"><?= e($l['website_name'] ?? '— unknown —') ?></td><?php endif; ?>
                <td data-label="Result"><span class="status <?= $ok ? 'status-ok' : ($l['http_status'] >= 500 ? 'status-error' : 'status-warn') ?>"><?= e($l['http_status']) ?> <?= e($l['result_code']) ?></span>
                    <?php if ($l['submission_id'] && $ok): ?> <a href="<?= e($ctx->url('/inbox/' . $l['submission_id'])) ?>">#<?= e($l['submission_id']) ?></a><?php endif; ?></td>
                <td data-label="Key id" class="mono"><?= e($l['key_id'] ?? '') ?></td>
                <td data-label="Submission ID" class="mono break"><?= e($l['submission_uid'] ?? '') ?></td>
                <td data-label="Sender IP"><?= e($l['ip']) ?></td>
                <td data-label="Bytes" class="num"><?= e(number_format((int) $l['payload_bytes'])) ?></td>
                <td data-label="ms" class="num"><?= e($l['duration_ms']) ?></td>
                <td data-label="Invalid fields" class="break"><?= e($l['error_fields'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
