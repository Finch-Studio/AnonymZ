<?php
// List of known affiliate query parameters
$affiliateParams = ['ref', 'ref_', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'aff'];

// Function to ensure proper scheme addition for URLs missing them
function ensureHttp($url) {
    // Check if the URL has a scheme. If not, prepend 'http://'
    if (!preg_match('/^https?:\/\//i', $url)) {
        // Assume 'http://' if no scheme is detected
        $url = 'http://' . $url;
    }
    // Ensure starting with 'www.' if no subdomain is apparent
    if (!preg_match('/^https?:\/\/(www\.|[\w]+\.[\w]+)/i', $url)) {
        $url = preg_replace('/^https?:\/\//i', '$0www.', $url);
    }
    return $url;
}

// Check if a URL parameter is present
$url = $_GET['url'] ?? '';

// First, normalize the URL by ensuring it has a scheme
$url = ensureHttp($url);

// Function to decide delay based on query complexity
function shouldDelayRedirect($queryParams) {
    return count($queryParams) > 3 || strlen(http_build_query($queryParams)) > 100;
}

// Validate and sanitize the URL
if (filter_var($url, FILTER_VALIDATE_URL)) {
    // Parse the URL
    $parsedUrl = parse_url($url);

    // Optionally remove 'www.'
    if (isset($parsedUrl['host']) && strpos($parsedUrl['host'], 'www.') === 0) {
        $parsedUrl['host'] = substr($parsedUrl['host'], 4);
    }

    $queryParams = [];
    if (isset($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $queryParams);
    }

    // Simplify Google Search URLs
    if (isset($parsedUrl['host']) && strpos($parsedUrl['host'], 'google.') !== false) {
        $queryParams = array_intersect_key($queryParams, array_flip(['q']));  // Keep only the 'q' parameter
    }

    // Remove affiliate parameters
    foreach ($affiliateParams as $param) {
        unset($queryParams[$param]);
    }

    // Rebuild the URL without affiliate parameters
    $url = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    if (isset($parsedUrl['path']) && !empty($parsedUrl['path'])) {
        $url .= $parsedUrl['path'];
    }
    if (!empty($queryParams)) {
        $url = $url . '?' . http_build_query($queryParams);
    }

    // Set Referrer-Policy header
    header('Referrer-Policy: no-referrer');

    $delay = shouldDelayRedirect($queryParams) ? 1 : 0; // Delay for 1 second if complex

    // Redirect using PHP header
    header("Refresh: $delay; url=$url");
    exit;
} else {
    $error = "Invalid URL. Please provide a valid URL including the correct scheme.";
    // Output error message and stop script execution
}
?>





<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $url ? 'Redirecting...' : 'Error'; ?></title>
    <?php if ($url): ?>
        <!-- Refresh page after 1 second to the URL -->
        <meta http-equiv="refresh" content="1; url=<?php echo htmlspecialchars($url); ?>">
    <?php endif; ?>
    <link rel="icon" type="image/png" href="/favicon.png" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #23272A;
            color: #FFFFFF;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            text-align: center;
        }
        .container {
            background-color: #2C2F33;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            margin: auto;
        }
		.powered {
        color: #FFFFFF; /* Change to white for better readability */
        margin-top: 20px;
		}
    </style>
</head>
<body>
    <div class="container">
        <?php if ($url): ?>
            <h1>Please Wait...</h1>
            <p>You are being redirected.</p>
            <p><a href="<?php echo htmlspecialchars($url); ?>" style="color: #7289DA;">Click here if you are not redirected automatically.</a></p>
        <?php else: ?>
            <h1>Error</h1>
            <p><?php echo htmlspecialchars($error); ?></p>
            <p><a href="/" style="color: #7289DA;">Return Home</a></p>
        <?php endif; ?>
		<p class="powered">Powered by <a href="https://anonymz.io/" style="color: #FFFFFF;">Anonymz</a></p>
    </div>
</body>
</html>
