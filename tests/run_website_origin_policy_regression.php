<?php
require_once dirname(__DIR__) . '/services/WebsiteOriginPolicy.php';

$failures = [];
$check = function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "PASS: {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL: {$message}\n";
};

$policy = new WebsiteOriginPolicy([
    'https://anytour.com',
    'https://www.anytour.com',
    'https://anytour.online',
]);

$check($policy->isAllowed('https://anytour.com'), 'main AnyTour origin is allowed');
$check($policy->isAllowed('https://www.anytour.com/'), 'allowed origin normalization removes trailing slash');
$check($policy->isAllowed('https://anytour.online'), 'production host origin is allowed');
$check($policy->isAllowed('') && $policy->isAllowed(null), 'same-origin requests without Origin remain allowed');
$check(!$policy->isAllowed('https://evil.example'), 'unknown origin is rejected');
$check(!$policy->isAllowed('https://anytour.com.evil.example'), 'suffix spoofing is rejected');
$check(!$policy->isAllowed('javascript://anytour.com'), 'non-http schemes are rejected');
$check(!$policy->isAllowed('https://anytour.com/path'), 'origins containing paths are rejected');

$apiSource = (string) file_get_contents(dirname(__DIR__) . '/website/api.php');
$check(strpos($apiSource, "REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'") !== false, 'WEBSITE API handles CORS preflight before application dispatch');
$check(strpos($apiSource, "origin_not_allowed") !== false, 'WEBSITE API rejects disallowed cross-origin callers');

if ($failures) {
    fwrite(STDERR, "Website origin policy regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEBSITE ORIGIN POLICY REGRESSION PASSED\n";
