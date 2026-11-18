# Shopify

Shopify's built-in contact form (`{% form 'contact' %}`) only emails the store owner. It does **not** emit a webhook for contact submissions, so it cannot be forwarded on its own. The supported server-side path is a **Shopify app proxy**:

```
Storefront form  →  https://<store>/apps/inquiries/submit  (Shopify signs the request)
                 →  relay script on your server (verifies the signature, holds the key)
                 →  {{ENDPOINT}}
```

Shopify forwards the request method and body and adds signed query parameters (`shop`, `logged_in_customer_id`, `path_prefix`, `timestamp`, `signature`). The relay checks the HMAC-SHA256 signature with the app's client secret before forwarding. No key or secret appears in Liquid, HTML or JavaScript.

## Prerequisites (need a real store)

1. A Shopify Partner account with access to the **Dev Dashboard** (new custom apps can no longer be created in the Shopify admin).
2. An app with **custom distribution** installed on the store (or on a development store for testing).
3. Access scope `write_app_proxy` and an app proxy configured (Dev Dashboard or `shopify.app.toml`).
4. A public **HTTPS** URL for the relay, e.g. `https://inquiries.example.com/shopify-proxy` on the same Hostinger account.
5. Theme edit access to add the form section.

## 1. Where the code goes

| File | Location |
|---|---|
| `app-proxy/index.php` + `app-proxy/.htaccess` | public folder for the relay, e.g. `public_html/shopify-proxy/` |
| `config.php` (from `config.example.php`) | private folder outside `public_html`, e.g. `~/domains/…/shopify-proxy-private/` |
| `InquiryClient.php` (from the plain PHP guide) | same private folder |
| `outbox/` (writable) | same private folder; retried by `retry-outbox.php` from cron |
| `theme/inquiry-form.liquid` | the store theme: **Online Store → Themes → Edit code → Sections → Add a new section** |

If the private folder is not two levels above `index.php`, set the environment variable `SHOPIFY_PROXY_CONFIG` to the full path of `config.php`.

## 2. Store the secrets

<!-- include: integrations/shopify/app-proxy/config.example.php lang=php -->

## 3. Configure the app proxy

<!-- include: integrations/shopify/shopify.app.toml.example -->

Or in the Dev Dashboard: app → **Versions → Create version → App proxy**: Prefix `apps`, Subpath `inquiries`, URL `https://inquiries.example.com/shopify-proxy`. Release the version, then install/update the app on the store. Merchants can change the subpath under **Settings → Apps → Customize URL**; keep it in sync with the form's `action`.

## 4. Relay (signature check and field mapping)

<!-- include: integrations/shopify/app-proxy/index.php lang=php -->

Form fields map as follows: `contact[name]` → `full_name`, `contact[email]`, `contact[phone]`, `contact[company]`, `contact[body]` → `message`, `products[i][title|sku|variant|quantity]` → `products`, `fields[Label]` → custom answers, `inquiry_type` → `form_type`. The relay replies with `Content-Type: application/liquid`, so Shopify shows the thank-you text inside the store theme. It never echoes visitor input into that Liquid.

## 5. Storefront form

<!-- include: integrations/shopify/theme/inquiry-form.liquid -->

## 6. Test and confirm receipt

Tested locally (automated): signature verification with Shopify's documented algorithm, stale-timestamp and wrong-shop rejection, field mapping, a double submit stored once, Liquid response.

Needs the real store:

1. Add the **Inquiry form** section to a page or product template and publish.
2. Submit it on the storefront; you should see the thank-you message inside the theme.
3. Check the inbox (**{{WEBSITE_NAME}}**) and this page's **Recent API requests**. A `401` in the relay's response means the client secret or proxy URL is wrong.
