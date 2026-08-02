# AnonymZ.io

AnonymZ.io is a PHP link redirector that strips referrer, tracking, and affiliate data from a destination URL before forwarding the client to it. It runs on Apache with `mod_rewrite` and `mod_headers`, plus PHP with the `curl` extension.

## Request Flow

There are two ways to invoke the redirector:

1. **Root passthrough**: `https://anonymz.io/?<destination>`
   `.htaccess` matches requests to `/` that carry a non-empty query string and internally rewrites them to `redirect.php`, preserving `REQUEST_URI` unchanged. `redirect.php` reads the destination directly from `REQUEST_URI` (everything after `/?`), which PHP has not decoded at all, so it undergoes exactly one `urldecode()` pass server side.
2. **Direct query parameter**: `/redirect.php?url=<destination>`
   PHP decodes `$_GET` values once automatically while parsing the query string, so this path skips the additional server-side decode.

Both paths converge on the same validation and rewrite pipeline in `redirect.php`:

1. Reject empty input.
2. Decode (see above, applied at most once per request regardless of entry point).
3. Normalize literal spaces to `%20` so `filter_var(..., FILTER_VALIDATE_URL)` does not reject them.
4. Enforce a maximum URL length of 4096 characters.
5. Reject `ftp:`, `file:`, `data:`, and `javascript:` schemes.
6. Reject input with more than one `http(s)://` prefix (for example `https://https://`).
7. Prepend `https://` if no scheme is present.
8. Validate the result with `filter_var(..., FILTER_VALIDATE_URL)` and `parse_url()`.
9. Reject URLs with userinfo in the host (`user@host`) and self-referential redirects back to `anonymz.io` or `www.anonymz.io`.
10. Strip a leading `www.` from the host.
11. If the host contains `google.`, drop every query parameter except `q`.
12. Strip known tracking and affiliate parameters: `ref`, `ref_`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `aff`.
13. Rebuild the URL from its validated parts (scheme, host, port, path, remaining query parameters, fragment).

If the rebuilt URL passes validation, the response is a redirect:

- `Location` header with HTTP 302, for simple query strings.
- `Refresh` header with a 1 second delay, when the query has more than 3 parameters or the built query string exceeds 100 characters (`shouldDelayRedirect()`), giving the client a moment on an interstitial page.

If validation fails at any step, the response is HTTP 200 with an HTML error page (not an HTTP error code, so the failure page itself is cacheable/crawlable behavior should be considered if that matters for your deployment) and, if `$enableFailureWebhook` is set, a failure notification is sent (see below).

## Decoding Behavior

The redirector decodes exactly one layer of percent-encoding per request, matched to exactly one layer of `encodeURIComponent()` applied client side in `assets/app.js`. Earlier versions of this project decoded in a loop until the string stopped changing; that approach corrupts any destination URL that legitimately contains percent-encoded characters in its own path or query (encoded spaces, encoded punctuation in article titles, a nested encoded URL in a tracking parameter), because each additional pass strips another layer of encoding that was never meant to be touched. If you modify this logic, keep the decode count fixed at one per entry point rather than reintroducing a "decode until stable" loop.

## Failure Telemetry

When `$enableFailureWebhook` is `true`, a failed redirect triggers a POST from `redirect.php` to `internal/error-webhook.php`, tagged with the `X-Internal-Hook` header. That script only accepts requests carrying that header (or CLI invocation) and forwards a Discord embed payload to `$webhookUrl`. The payload contains the error message, a truncated SHA-256 fingerprint of the failed input (not the input itself), and a UTC timestamp. No destination URLs or identifying request data are sent.

`internal/error-webhook.php` ships with a placeholder `$webhookUrl`. Replace it with your actual Discord webhook URL before deploying, and avoid committing the real value to version control.

## Security Headers

`.htaccess` sets the following on every response:

- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `Permissions-Policy: geolocation=(), microphone=(), camera=()`
- `Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache`, to prevent caching of redirect responses

HTTPS is enforced for all hosts except `localhost` (`RewriteCond %{HTTPS} off` / `RewriteCond %{HTTP_HOST} !^localhost$`).

## File Structure

```
.htaccess                   Rewrite rules, HTTPS enforcement, security headers
index.html                  Front-end: URL input form, About/Usage sections
assets/app.css              Site styling
assets/app.js                Front-end logic: menu handling, URL encoding, clipboard copy
redirect.php                 Redirector: validation, rewriting, redirect, error page
internal/error-webhook.php  Internal-only proxy that forwards failure telemetry to Discord
404.html / 500.html         Static error pages configured via ErrorDocument
```

## Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/Afinity-Labs/AnonymZ.git
   ```
2. Point an Apache vhost with `mod_rewrite` and `mod_headers` enabled at the repository root, and confirm `AllowOverride All` (or the equivalent) so `.htaccess` is honored.
3. Confirm PHP has the `curl` extension enabled (required by `redirect.php` and `internal/error-webhook.php`).
4. Set `$selfHosts` in `redirect.php` to match your domain(s), so the self-redirect check works correctly.
5. Set `$webhookUrl` in `internal/error-webhook.php` to your real Discord webhook URL, or set `$enableFailureWebhook = false` in `redirect.php` to disable failure telemetry entirely.

## Contributing

1. Fork the repository.
2. Create a branch: `git checkout -b feature-branch`
3. Commit your changes: `git commit -am 'Add some feature'`
4. Push the branch: `git push origin feature-branch`
5. Open a pull request.

Bug reports and feature requests should go through GitHub issues.

## License

You may use the live AnonymZ.io service without restriction. Self-hosting is permitted; if you deploy your own instance, review and adjust the code for your environment. You may remove the "View on GitHub" button, but if you modify, rebuild, or reuse parts of this project, the source code location must remain visible somewhere in your deployment.

Reference snippet for a floating GitHub link, if not using the button in `index.html`:

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
  .github-link {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1000;
    text-decoration: none;
  }

  .github-button {
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
    transition: opacity 0.3s;
  }

  .github-button i {
    font-size: 50px;
    color: #ffffff;
  }

  .github-link:hover .github-button {
    opacity: 1;
  }

  .tooltip {
    position: absolute;
    bottom: 60px;
    right: 10px;
    background-color: rgba(0, 0, 0, 0.8);
    color: #fff;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s, visibility 0.3s;
  }

  .github-link:hover .tooltip {
    opacity: 1;
    visibility: visible;
  }
</style>

<a href="https://github.com/Afinity-Labs/AnonymZ" target="_blank" class="github-link">
  <div class="github-button">
    <i class="fa-brands fa-github"></i>
  </div>
  <span class="tooltip">View Source</span>
</a>
```

Note: the CDN stylesheet above (`cdnjs.cloudflare.com`) will not load under this project's own Content-Security-Policy, which restricts `style-src` to `'self' 'unsafe-inline'`. If you use this snippet on a deployment that inherits this repository's `.htaccess`, either self-host the Font Awesome CSS or add the CDN origin to `style-src`.

## Donations

**PayPal:** https://paypal.me/FinchStudio

**Bitcoin (BTC):** `bc1qfnpg8lvw65349utkezqx8j484ng0dlgv4x0cns`

**Ethereum (ETH):** `0x3F3AAc69d3Eb2A397670651d04355650d39e5d0f`

**Solana (SOL):** `9J3TdWRXF5EJALtDZcaikqF5vhEihT8AMxnjm3VGkzVL`
