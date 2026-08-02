<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// --------------------------------------------------
// Configuration
// --------------------------------------------------

$affiliateParams = [
    'ref', 'ref_', 'utm_source', 'utm_medium',
    'utm_campaign', 'utm_term', 'utm_content', 'aff'
];

$maxUrlLength = 4096;
$selfHosts    = ['anonymz.io', 'www.anonymz.io'];

// Toggle failure telemetry (privacy-safe)
$enableFailureWebhook = true;

// Internal webhook proxy (NOT the real webhook)
$failureWebhookEndpoint = 'https://anonymz.io/internal/error-webhook.php';

// --------------------------------------------------
// Helpers
// --------------------------------------------------

function shouldDelayRedirect(array $queryParams): bool {
    return count($queryParams) > 3 || strlen(http_build_query($queryParams)) > 100;
}

/**
 * Returns [url, needsDecode]. PHP already URL-decodes $_GET values once
 * when parsing the query string, so that path needs no further decoding.
 * The raw REQUEST_URI passthrough is untouched by PHP and carries exactly
 * one layer of encoding (added by assets/app.js), so it needs one decode.
 */
function normalizeInputUrl(): array {
    if (!empty($_GET['url'])) {
        // Direct access: /redirect.php?url=https://example.com
        return [trim($_GET['url']), false];
    }

    // Root passthrough: https://anonymz.io/?https://example.com
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return [trim(ltrim($uri, '/?')), true];
}

/**
 * Privacy-safe failure telemetry
 */
function sendFailureWebhook(string $error, string $inputUrl): void
{
    global $enableFailureWebhook, $failureWebhookEndpoint;

    if (!$enableFailureWebhook || !$failureWebhookEndpoint) {
        return;
    }

    // One-way fingerprint (non-reversible)
    $fingerprint = hash('sha256', $inputUrl);

    $payload = [
        'embeds' => [[
            'title' => 'AnonymZ Redirect Failure',
            'color' => 15158332,
            'fields' => [
                [
                    'name' => 'Error',
                    'value' => $error,
                    'inline' => true
                ],
                [
                    'name' => 'Fingerprint',
                    'value' => substr($fingerprint, 0, 12),
                    'inline' => true
                ],
                [
                    'name' => 'Time',
                    'value' => gmdate('Y-m-d H:i:s') . ' UTC'
                ]
            ]
        ]]
    ];

    $ch = curl_init($failureWebhookEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Internal-Hook: 1'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT_MS     => 300,
    ]);

    @curl_exec($ch);
    @curl_close($ch);
}

// --------------------------------------------------
// Init
// --------------------------------------------------

[$inputUrl, $needsDecode] = normalizeInputUrl();
$finalUrl    = '';
$queryParams = [];
$error       = null;

// --------------------------------------------------
// Input handling
// --------------------------------------------------

if ($inputUrl === '') {
    $error = 'Missing destination URL.';
} else {

    // Decode exactly one layer of encoding. Looping "until stable" here
    // would keep eating into the destination URL's own percent-encoding
    // (e.g. %20 in a query value, or %25 in a Wikipedia title), corrupting
    // any destination that legitimately contains encoded characters.
    if ($needsDecode) {
        $inputUrl = urldecode($inputUrl);
    }

    // Normalize spaces after decoding
    $inputUrl = str_replace(' ', '%20', $inputUrl);

    // Length limit
    if (strlen($inputUrl) > $maxUrlLength) {
        $error = 'URL too long.';
    }

    // Reject dangerous or unsupported schemes BEFORE forcing https
    if (
        !$error &&
        preg_match('#^(ftp|file|data|javascript):#i', $inputUrl)
    ) {
        $error = 'Unsupported URL scheme.';
    }

    // Reject multiple http(s) schemes
    if (
        !$error &&
        preg_match('#^(https?://){2,}#i', $inputUrl)
    ) {
        $error = 'Invalid URL format.';
    }

    // Force https ONLY if no scheme exists
    if (
        !$error &&
        !preg_match('#^https?://#i', $inputUrl)
    ) {
        $inputUrl = 'https://' . $inputUrl;
    }
}

// --------------------------------------------------
// Validation & cleanup
// --------------------------------------------------

if (!$error && filter_var($inputUrl, FILTER_VALIDATE_URL)) {

    $parsedUrl = parse_url($inputUrl);

    if (
        empty($parsedUrl['scheme']) ||
        empty($parsedUrl['host']) ||
        !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)
    ) {
        $error = 'Invalid or unsupported URL.';
    }

    // Block userinfo abuse
    elseif (strpos($parsedUrl['host'], '@') !== false) {
        $error = 'Invalid URL host.';
    }

    // Prevent self-redirect loops
    elseif (in_array(strtolower($parsedUrl['host']), $selfHosts, true)) {
        $error = 'Self redirects are not allowed.';
    }

    else {

        // Normalize host
        if (strpos($parsedUrl['host'], 'www.') === 0) {
            $parsedUrl['host'] = substr($parsedUrl['host'], 4);
        }

        // Parse query string
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        // Google search hardening
        if (strpos($parsedUrl['host'], 'google.') !== false) {
            $queryParams = array_intersect_key($queryParams, ['q' => true]);
        }

        // Strip tracking / affiliate params
        foreach ($affiliateParams as $param) {
            unset($queryParams[$param]);
        }

        // --------------------------------------------------
        // Rebuild clean URL
        // --------------------------------------------------

        $finalUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        if (!empty($parsedUrl['port'])) {
            $finalUrl .= ':' . $parsedUrl['port'];
        }

        if (!empty($parsedUrl['path'])) {
            $finalUrl .= $parsedUrl['path'];
        }

        if (!empty($queryParams)) {
            $finalUrl .= '?' . http_build_query($queryParams);
        }

        if (!empty($parsedUrl['fragment'])) {
            $finalUrl .= '#' . $parsedUrl['fragment'];
        }
    }

} elseif (!$error) {
    $error = 'Invalid URL. Please provide a valid destination URL.';
}

// --------------------------------------------------
// Headers
// --------------------------------------------------

header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

// --------------------------------------------------
// Redirect (ONLY if final URL is valid)
// --------------------------------------------------

if ($finalUrl && filter_var($finalUrl, FILTER_VALIDATE_URL)) {
    if (shouldDelayRedirect($queryParams)) {
        header("Refresh: 1; url={$finalUrl}");
    } else {
        header("Location: {$finalUrl}", true, 302);
    }
    exit;
}

// If we reach here, treat as handled error
$finalUrl = '';
$error = $error ?: 'The destination URL is invalid.';


// --------------------------------------------------
// Failure telemetry (ONLY on error)
// --------------------------------------------------

if ($error) {
    sendFailureWebhook($error, $inputUrl);
}

// --------------------------------------------------
// Error output
// --------------------------------------------------

http_response_code(200);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $finalUrl ? 'Redirecting…' : 'Unable to Redirect'; ?></title>

    <?php if ($finalUrl && shouldDelayRedirect($queryParams)): ?>

    <?php endif; ?>

    <link rel="icon" type="image/png" href="/favicon.png" />

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #23272A;
            color: #FFFFFF;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .container {
            background-color: #2C2F33;
            border-radius: 12px;
            width: 420px;
            height: 320px;
            padding: 24px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.35);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 1.4rem;
            font-weight: 600;
        }

        p {
            margin: 0;
            color: #DCDDDE;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .content {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .action {
            margin-top: 8px;
        }

        a {
            color: #7289DA;
            text-decoration: none;
            font-weight: 500;
        }

        a:hover {
            text-decoration: underline;
        }

        .footer {
            font-size: 0.8rem;
            color: #9DA3A6;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="content">
        <?php if ($finalUrl): ?>
            <h1>Redirecting</h1>
            <p>
                You are being securely redirected to your destination.
            </p>

            <div class="action">
                <a href="<?= htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    Continue manually
                </a>
            </div>
        <?php else: ?>
            <h1>Unable to Redirect</h1>
            <p>
                The provided link could not be processed.
                This usually happens if the URL is malformed or unsupported.
            </p>

            <div class="action">
                <a href="/">Return Home</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        Powered by <a href="https://anonymz.io/">AnonymZ</a>
    </div>
</div>

</body>
</html>

<?php exit; ?>
