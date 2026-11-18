<?php
use App\Auth\Gate;
use App\Support\Time;

$tz = $ctx->timezone();
$me = $ctx->me();
$roleLabels = ['developer' => 'Developer', 'director' => 'Director', 'user' => 'User'];
?>
<div class="page-head">
    <h1>Users</h1>
    <a class="btn btn-primary" href="<?= e($ctx->url('/users/new')) ?>">Create account</a>
</div>
<div class="table-wrap">
    <table class="table">
        <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Website access</th><th scope="col">Last sign-in</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td data-label="Name"><a href="<?= e($ctx->url('/users/' . $u['id'])) ?>"><strong><?= e($u['name']) ?></strong></a>
                    <?= (int) $u['id'] === (int) $me['id'] ? ' <span class="tag">You</span>' : '' ?>
                    <span class="sub"><?= e($u['email']) ?></span></td>
                <td data-label="Role"><?= e($roleLabels[$u['role']]) ?><?= !Gate::canManageUser($me, $u) ? ' <span class="sub">read-only for you</span>' : '' ?></td>
                <td data-label="Status">
                    <span class="status <?= $u['status'] === 'active' ? 'status-ok' : 'status-off' ?>"><?= e(ucfirst($u['status'])) ?></span>
                    <?= (int) $u['must_change_password'] === 1 ? ' <span class="tag">Temporary password</span>' : '' ?>
                </td>
                <td data-label="Website access"><?= $u['role'] === 'user' ? ((int) $u['website_count'] === 0 ? '<span class="status status-warn">None assigned</span>' : e($u['website_count']) . ' website' . ((int) $u['website_count'] === 1 ? '' : 's')) : 'All websites' ?></td>
                <td data-label="Last sign-in"><?= $u['last_login_at'] ? e(Time::local($u['last_login_at'], $tz)) : '<span class="muted">Never</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
