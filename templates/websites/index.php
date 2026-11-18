<?php
use App\Domain\WebsiteService;
use App\Support\Time;

$tz = $ctx->timezone();
$dev = $ctx->can('websites.lifecycle');
?>
<div class="page-head">
    <h1>Websites</h1>
    <?php if ($dev): ?><a class="btn btn-primary" href="<?= e($ctx->url('/websites/new')) ?>">Register website</a><?php endif; ?>
</div>

<?php if (!$websites): ?>
    <div class="empty">
        <h2 class="h3">No websites yet</h2>
        <p><?= $dev ? 'Register the first website to create its API key.' : 'A Developer registers websites. They will appear here.' ?></p>
    </div>
<?php else: ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th scope="col">Website</th><th scope="col">Platform</th><th scope="col">Status</th>
            <th scope="col" class="num">Inquiries</th><th scope="col">Last submission</th>
            <?php if ($dev): ?><th scope="col">API keys</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($websites as $w): ?>
            <tr>
                <td data-label="Website"><a href="<?= e($ctx->url('/websites/' . $w['id'])) ?>"><strong><?= e($w['name']) ?></strong></a><span class="sub"><?= e($w['domain']) ?></span></td>
                <td data-label="Platform"><?= e(WebsiteService::PLATFORMS[$w['platform']] ?? $w['platform']) ?></td>
                <td data-label="Status"><span class="status status-site-<?= e($w['status']) ?>"><?= e(ucfirst($w['status'])) ?></span></td>
                <td data-label="Inquiries" class="num"><a href="<?= e($ctx->url('/inbox', ['website' => $w['id']])) ?>"><?= e(number_format((int) $w['submission_count'])) ?></a></td>
                <td data-label="Last submission"><?= $w['last_submission_at'] ? e(Time::local($w['last_submission_at'], $tz)) : '<span class="muted">Never</span>' ?></td>
                <?php if ($dev): ?>
                    <td data-label="API keys"><a href="<?= e($ctx->url('/websites/' . $w['id'] . '/connect')) ?>"><?= (int) $w['active_keys'] > 0 ? e($w['active_keys']) . ' active' : 'None — create' ?></a></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
