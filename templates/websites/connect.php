<?php
use App\Domain\WebsiteService;
use App\Support\Time;

$tz = $ctx->timezone();
$activeKeys = array_filter($keys, fn ($k) => $k['status'] === 'active' && ($k['expires_at'] === null || $k['expires_at'] > utc_now()));
$curl = "curl -X POST " . $endpoint . " \\\n  -H \"Authorization: Bearer \$INQUIRY_API_KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -H \"Idempotency-Key: contact-2026-11-18-7f3c9a1e\" \\\n  --data @inquiry.json";
?>
<div class="page-head">
    <div>
        <p class="crumb"><a href="<?= e($ctx->url('/websites/' . $site['id'])) ?>">← <?= e($site['name']) ?></a></p>
        <h1>API Connect</h1>
        <p class="muted"><?= e($site['name']) ?> · <?= e($site['domain']) ?> · <span class="status status-site-<?= e($site['status']) ?>"><?= e(ucfirst($site['status'])) ?></span></p>
    </div>
</div>

<?php if ($newToken !== null): ?>
    <section class="secret-box" role="alert" aria-labelledby="secret-h">
        <h2 id="secret-h" class="h3">Copy the new key now</h2>
        <p>This is the only time the full key is shown. Store it on the sending server (environment variable or server-side config), never in browser JavaScript, HTML or Shopify Liquid.</p>
        <div class="copy-row">
            <input type="text" readonly value="<?= e($newToken) ?>" id="new-token" class="mono" aria-label="New API key" data-select-on-focus>
            <button type="button" class="btn btn-primary" data-copy="#new-token">Copy key</button>
        </div>
    </section>
<?php endif; ?>

<div class="grid-2">
    <section class="panel" aria-labelledby="ep-h">
        <h2 id="ep-h" class="h3">Endpoint</h2>
        <dl class="deflist deflist-tight">
            <div><dt>URL</dt><dd class="copy-row"><code id="endpoint" class="break"><?= e($endpoint) ?></code> <button type="button" class="btn btn-small" data-copy="#endpoint">Copy</button></dd></div>
            <div><dt>Method</dt><dd><code>POST</code>, <code>Content-Type: application/json</code></dd></div>
            <div><dt>Authentication</dt><dd><code>Authorization: Bearer &lt;website key&gt;</code></dd></div>
            <div><dt>Credential status</dt><dd>
                <?php if ($site['status'] === 'archived'): ?><span class="status status-site-archived">Archived — keys revoked</span>
                <?php elseif (!$activeKeys): ?><span class="status status-warn">No active key</span>
                <?php elseif ($site['status'] !== 'active'): ?><span class="status status-warn">Website inactive — submissions rejected</span>
                <?php else: ?><span class="status status-ok"><?= e(count($activeKeys)) ?> active key<?= count($activeKeys) === 1 ? '' : 's' ?></span><?php endif; ?>
            </dd></div>
            <div><dt>Last successful submission</dt><dd>
                <?= $lastSuccess ? e(Time::local($lastSuccess['occurred_at'], $tz, 'd M Y, H:i:s')) . ($lastSuccess['submission_id'] ? ' · <a href="' . e($ctx->url('/inbox/' . $lastSuccess['submission_id'])) . '">inquiry #' . e($lastSuccess['submission_id']) . '</a>' : '') : '<span class="muted">None yet</span>' ?>
            </dd></div>
            <div><dt>Limit</dt><dd><?= e($site['rate_limit_per_minute']) ?> requests per minute · body up to <?= e(number_format((int) $ctx->config->get('api.max_body_bytes'))) ?> bytes</dd></div>
        </dl>
    </section>

    <section class="panel" aria-labelledby="keys-h">
        <h2 id="keys-h" class="h3">Keys</h2>
        <?php if ($site['status'] !== 'archived'): ?>
        <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/keys')) ?>" class="key-form" data-busy>
            <?= $ctx->csrf->field() ?>
            <div class="field">
                <label for="label">Label <span class="hint">optional, e.g. "Production server"</span></label>
                <input id="label" name="label" maxlength="80">
            </div>
            <div class="field">
                <label for="mode">Action</label>
                <select id="mode" name="mode">
                    <option value="new"><?= $activeKeys ? 'Add another key (keep existing keys)' : 'Create key' ?></option>
                    <?php if ($activeKeys): ?>
                        <option value="rotate_24h">Rotate: new key, old keys expire in 24 hours</option>
                        <option value="rotate_now">Rotate now: new key, revoke old keys immediately</option>
                    <?php endif; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Generate key</button>
        </form>
        <?php endif; ?>
        <?php if (!$keys): ?>
            <p class="muted">No keys yet.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th scope="col">Key</th><th scope="col">Status</th><th scope="col">Last used</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($keys as $k):
                    $expired = $k['expires_at'] !== null && $k['expires_at'] <= utc_now();
                    $state = $k['status'] === 'revoked' ? 'Revoked' : ($expired ? 'Expired' : ($k['expires_at'] ? 'Expires ' . Time::local($k['expires_at'], $tz) : 'Active'));
                    ?>
                    <tr>
                        <td data-label="Key"><code>aiq_<?= e($k['key_id']) ?>_…<?= e($k['last_four']) ?></code>
                            <span class="sub"><?= e($k['label'] ?? '') ?> created <?= e(Time::local($k['created_at'], $tz, 'd M Y')) ?><?= $k['created_by_name'] ? ' by ' . e($k['created_by_name']) : '' ?></span></td>
                        <td data-label="Status"><span class="status <?= $state === 'Active' ? 'status-ok' : ($k['status'] === 'revoked' || $expired ? 'status-off' : 'status-warn') ?>"><?= e($state) ?></span></td>
                        <td data-label="Last used"><?= $k['last_used_at'] ? e(Time::local($k['last_used_at'], $tz)) : '<span class="muted">Never</span>' ?></td>
                        <td>
                            <?php if ($k['status'] === 'active' && !$expired): ?>
                                <form method="post" action="<?= e($ctx->url('/websites/' . $site['id'] . '/keys/' . $k['id'] . '/revoke')) ?>" data-confirm="Revoke this key? Servers using it will be rejected immediately.">
                                    <?= $ctx->csrf->field() ?>
                                    <button type="submit" class="btn btn-small btn-danger">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="panel ai-prompt" aria-labelledby="ai-h">
    <div class="panel-head">
        <h2 id="ai-h" class="h3">Let Claude Code connect this website</h2>
        <form method="get" action="<?= e($ctx->url('/websites/' . $site['id'] . '/connect')) ?>" class="inline-form">
            <label for="platform-ai">Website built with</label>
            <select id="platform-ai" name="platform" data-autosubmit>
                <?php foreach (WebsiteService::PLATFORMS as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $platform === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-small">Show</button></noscript>
        </form>
    </div>
    <ol class="ai-steps">
        <li><strong>Put the key on the website's server</strong>: <?php if ($platform === 'wordpress'): ?>in <code>wp-config.php</code> as <code>AIQ_API_KEY</code><?php else: ?>as the environment variable <code>INQUIRY_API_KEY</code>, next to <code>INQUIRY_API_URL</code><?php endif; ?>. <?= $activeKeys ? 'Use the key you copied when it was created, or generate a new one above.' : 'Generate a key above first.' ?></li>
        <li><strong>Open a terminal in the website's project folder</strong> and start Claude Code: <code>claude</code></li>
        <li><strong>Paste the prompt below.</strong> Claude explores the code, shows you a plan of which forms it will connect, writes the code and tests, and ends with the steps you still need to do (environment variables, cron, deploy).</li>
        <li><strong>Check the result</strong> under <em>Recent API requests</em> at the bottom of this page.</li>
    </ol>
    <p class="small muted">The prompt includes the endpoint, the full API contract, retry rules and <?= e(WebsiteService::PLATFORMS[$platform]) ?> guidance for <?= e($site['name']) ?>. It never contains the API key, so the key is not sent to an AI service or written into the code.</p>
    <div class="code-block">
        <button type="button" class="btn btn-small copy-btn" data-copy="#ai-prompt-text">Copy prompt</button>
        <pre class="prompt-pre"><code id="ai-prompt-text"><?= e($aiPrompt) ?></code></pre>
    </div>
</section>

<section class="panel" aria-labelledby="sample-h">
    <h2 id="sample-h" class="h3">Sample request and response</h2>
    <div class="grid-2 grid-tight">
        <div>
            <h3 class="h4">Request body (<code>inquiry.json</code>)</h3>
            <div class="code-block"><button type="button" class="btn btn-small copy-btn" data-copy-next>Copy</button><pre><code><?= e($sample) ?></code></pre></div>
            <h3 class="h4">Send it from the server</h3>
            <div class="code-block"><button type="button" class="btn btn-small copy-btn" data-copy-next>Copy</button><pre><code><?= e($curl) ?></code></pre></div>
        </div>
        <div>
            <h3 class="h4"><code>201 Created</code> (a retry with the same ID returns <code>200</code> with <code>"replayed": true</code>)</h3>
            <div class="code-block"><pre><code><?= e($sampleResponse) ?></code></pre></div>
            <h3 class="h4">Error responses</h3>
            <div class="table-wrap"><table class="table table-compact">
                <thead><tr><th scope="col">Status</th><th scope="col">code</th><th scope="col">Retry?</th></tr></thead>
                <tbody>
                    <tr><td>400</td><td><code>invalid_json</code></td><td>No — fix the body</td></tr>
                    <tr><td>401</td><td><code>invalid_credentials</code></td><td>No — check or replace the key</td></tr>
                    <tr><td>403</td><td><code>website_inactive</code>, <code>https_required</code></td><td>No</td></tr>
                    <tr><td>409</td><td><code>idempotency_conflict</code></td><td>No — same ID, different payload</td></tr>
                    <tr><td>413</td><td><code>payload_too_large</code></td><td>No — shorten the payload</td></tr>
                    <tr><td>415</td><td><code>unsupported_media_type</code></td><td>No</td></tr>
                    <tr><td>422</td><td><code>validation_failed</code> + <code>fields</code></td><td>No — fix the mapping</td></tr>
                    <tr><td>429</td><td><code>rate_limited</code> + <code>Retry-After</code></td><td>Yes, same ID, after the delay</td></tr>
                    <tr><td>5xx / timeout</td><td><code>server_error</code></td><td>Yes, same ID, with backoff</td></tr>
                </tbody>
            </table></div>
        </div>
    </div>
    <p class="small">Full contract: <code>docs/api.md</code> in the application repository.</p>
</section>

<section class="panel" aria-labelledby="guide-h">
    <div class="panel-head">
        <h2 id="guide-h" class="h3">Integration instructions</h2>
        <form method="get" action="<?= e($ctx->url('/websites/' . $site['id'] . '/connect')) ?>" class="inline-form">
            <label for="platform-guide" class="visually-hidden">Platform</label>
            <select id="platform-guide" name="platform" data-autosubmit>
                <?php foreach (WebsiteService::PLATFORMS as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $platform === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-small">Show</button></noscript>
        </form>
    </div>
    <?php if ($platform === 'wordpress'): ?>
        <p>
            <?php if ($hasPluginZip): ?>
                <a class="btn btn-primary" href="<?= e($ctx->url('/downloads/wordpress-plugin')) ?>">Download WordPress plugin (ZIP)</a>
            <?php else: ?>
                <span class="alert alert-warning">The plugin ZIP has not been built. Run <code>php bin/build-wordpress-plugin.php</code>.</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <div class="guide"><?= $guide ?></div>
</section>

<section class="panel" aria-labelledby="diag-h">
    <div class="panel-head">
        <h2 id="diag-h" class="h3">Recent API requests</h2>
        <a class="small" href="<?= e($ctx->url('/diagnostics', ['website' => $site['id']])) ?>">All diagnostics</a>
    </div>
    <?php if (!$recent): ?>
        <p class="muted">No requests received for this website yet. Send a test inquiry using the guide above.</p>
    <?php else: ?>
        <?= $view->partial('audit/diagnostics-table', ['rows' => $recent, 'tz' => $tz, 'showSite' => false]) ?>
    <?php endif; ?>
</section>
