<?php

/*
 * Copy to the private folder (outside public_html) as config.php. Never commit real values.
 */
return [
    // Dev Dashboard > your app > Settings > Client secret. Used only to verify Shopify's proxy signature.
    'shopify_client_secret' => 'shpss_REPLACE_ME',
    // The store's permanent domain, e.g. "atlantic-store.myshopify.com".
    'shop_domain' => 'your-store.myshopify.com',
    // Inquiry Manager endpoint and the Shopify website's key from its API Connect page.
    'inquiry_api_url' => 'https://inquiries.example.com/api/v1/submissions',
    'inquiry_api_key' => 'aiq_REPLACE_ME',
    // Writable directory for undelivered inquiries (retried by retry-outbox.php from cron).
    'outbox_dir' => __DIR__ . '/outbox',
];
