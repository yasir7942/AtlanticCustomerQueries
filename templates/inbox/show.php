<?php
use App\Domain\Submissions\Presenter;
use App\Support\Time;

$tz = $ctx->timezone();
$backQuery = is_array($back ?? null) ? $back : [];
$contact = [];
foreach (Presenter::CONTACT_LABELS as $key => $label) {
    if ((string) $row[$key] !== '') {
        $contact[$key] = $label;
    }
}
$messageDir = Presenter::dir((string) $row['message']);
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/inbox', $backQuery)) ?>">← Back to inbox</a></p>
        <h1>
            <span class="type type-<?= e($row['form_type']) ?>"><?= e(Presenter::TYPE_LABELS[$row['form_type']]) ?></span>
            <bdi dir="<?= e(Presenter::dir(Presenter::customer($row))) ?>"><?= e(Presenter::customer($row)) ?></bdi>
        </h1>
        <p class="muted">
            Inquiry #<?= e($row['id']) ?> · <?= e($row['website_name']) ?> (<?= e($row['website_domain']) ?>)
            · received <time datetime="<?= e(Time::iso($row['received_at'])) ?>"><?= e(Time::local($row['received_at'], $tz, 'D d M Y, H:i')) ?></time>
            <?php if ($row['is_test']): ?> · <span class="tag">Test submission</span><?php endif; ?>
        </p>
    </div>
    <form method="post" action="<?= e($ctx->url('/inbox/' . $row['id'] . '/status')) ?>" class="status-form">
        <?= $ctx->csrf->field() ?>
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (Presenter::STATUS_LABELS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $row['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Update</button>
    </form>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <?php if ((string) $row['message'] !== ''): ?>
            <section class="panel" aria-labelledby="msg-h">
                <h2 id="msg-h" class="h3">Message</h2>
                <div class="message" dir="<?= e($messageDir) ?>" lang="<?= e($row['locale']) ?>"><?= nl2br(e($row['message'])) ?></div>
            </section>
        <?php endif; ?>

        <?php if ($products): ?>
            <section class="panel" aria-labelledby="prod-h">
                <h2 id="prod-h" class="h3">Products (<?= e(count($products)) ?>)</h2>
                <div class="table-wrap">
                    <table class="table table-compact">
                        <thead><tr><th scope="col">Product</th><th scope="col">SKU</th><th scope="col">Variant</th><th scope="col" class="num">Quantity</th><th scope="col">Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td data-label="Product"><bdi><?= e($p['name'] ?? '—') ?></bdi></td>
                                <td data-label="SKU"><?= e($p['sku'] ?? '') ?></td>
                                <td data-label="Variant"><?= e($p['variant'] ?? '') ?></td>
                                <td data-label="Quantity" class="num"><?= isset($p['quantity']) ? e(Presenter::number($p['quantity'])) . (isset($p['unit']) ? ' ' . e($p['unit']) : '') : '—' ?></td>
                                <td data-label="Notes" dir="auto"><?= nl2br(e($p['notes'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($fields): ?>
            <section class="panel" aria-labelledby="fields-h">
                <h2 id="fields-h" class="h3">Form answers</h2>
                <dl class="deflist">
                    <?php foreach ($fields as $f): ?>
                        <div>
                            <dt><bdi><?= e($f['label']) ?></bdi></dt>
                            <dd dir="auto">
                                <?php if ($f['is_list']): ?>
                                    <ul class="plain"><?php foreach ($f['values'] as $v): ?><li><?= e($v) ?></li><?php endforeach; ?></ul>
                                <?php else: ?>
                                    <?= nl2br(e($f['display'])) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php if (!$products && !$fields && (string) $row['message'] === ''): ?>
            <p class="muted">This inquiry has no message, products or extra answers.</p>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <section class="panel" aria-labelledby="contact-h">
            <h2 id="contact-h" class="h3">Contact</h2>
            <?php if (!$contact): ?>
                <p class="muted">No contact details were supplied.</p>
            <?php else: ?>
                <dl class="deflist deflist-tight">
                    <?php foreach ($contact as $key => $label): ?>
                        <div>
                            <dt><?= e($label) ?></dt>
                            <dd dir="auto">
                                <?php if ($key === 'email' && filter_var($row['email'], FILTER_VALIDATE_EMAIL)): ?>
                                    <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
                                <?php elseif ($key === 'phone'): ?>
                                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $row['phone'])) ?>"><?= e($row['phone']) ?></a>
                                <?php else: ?>
                                    <?= nl2br(e($row[$key])) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="meta-h">
            <h2 id="meta-h" class="h3">Details</h2>
            <dl class="deflist deflist-tight">
                <div><dt>Form</dt><dd><?= e($row['form_label'] ?: $row['form_key']) ?></dd></div>
                <div><dt>Language</dt><dd><?= e($row['locale']) ?></dd></div>
                <div><dt>Received (UTC)</dt><dd><?= e($row['received_at']) ?></dd></div>
                <?php if ($row['source_created_at']): ?>
                    <div><dt>Submitted on site</dt><dd><?= e(Time::local($row['source_created_at'], $tz)) ?></dd></div>
                <?php endif; ?>
                <?php if ($row['source_page_url']): ?>
                    <div><dt>Page</dt><dd class="break"><?= e($row['source_page_url']) ?></dd></div>
                <?php endif; ?>
                <?php if ($row['source_ip'] !== ''): ?>
                    <div><dt>Visitor IP</dt><dd><?= e($row['source_ip']) ?></dd></div>
                <?php endif; ?>
                <?php if ($row['source_email_sent'] !== null): ?>
                    <div><dt>Site email</dt><dd><?= (int) $row['source_email_sent'] === 1 ? 'Sent' : 'Not sent' ?>
                        <?= $row['source_email_sent_at'] ? ' · ' . e(Time::local($row['source_email_sent_at'], $tz)) : '' ?>
                        <?= $row['source_email_attempts'] !== null ? ' · ' . e($row['source_email_attempts']) . ' attempt(s)' : '' ?>
                        <?= $row['source_email_error'] ? '<br><span class="sub">' . e($row['source_email_error']) . '</span>' : '' ?></dd></div>
                <?php endif; ?>
                <?php if ($row['source_dedupe_key']): ?>
                    <div><dt>Site dedupe key</dt><dd class="break mono"><?= e($row['source_dedupe_key']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Submission ID</dt><dd class="break mono"><?= e($row['submission_uid']) ?></dd></div>
                <?php if ($row['status_changed_at']): ?>
                    <div><dt>Status changed</dt><dd><?= e(Time::local($row['status_changed_at'], $tz)) ?><?= $row['status_changed_by_name'] ? ' by ' . e($row['status_changed_by_name']) : '' ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>
    </aside>
</div>
