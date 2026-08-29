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
    'https://anytoour.ru',
    'https://www.anytoour.ru',
]);

$check($policy->isAllowed('https://anytour.com'), 'main AnyTour origin is allowed');
$check($policy->isAllowed('https://www.anytour.com/'), 'allowed origin normalization removes trailing slash');
$check($policy->isAllowed('https://anytour.online'), 'production host origin is allowed');
$check($policy->isAllowed('https://anytoour.ru'), 'Anytoour origin is allowed');
$check($policy->isAllowed('https://www.anytoour.ru/'), 'Anytoour www origin is allowed');
$check($policy->isAllowed('') && $policy->isAllowed(null), 'same-origin requests without Origin remain allowed');
$check(!$policy->isAllowed('https://evil.example'), 'unknown origin is rejected');
$check(!$policy->isAllowed('https://anytour.com.evil.example'), 'suffix spoofing is rejected');
$check(!$policy->isAllowed('https://anytoour.ru.evil.example'), 'Anytoour suffix spoofing is rejected');
$check(!$policy->isAllowed('javascript://anytour.com'), 'non-http schemes are rejected');
$check(!$policy->isAllowed('https://anytour.com/path'), 'origins containing paths are rejected');

$canonicalApiSource = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/api.php');
$legacyApiSource = (string) file_get_contents(dirname(__DIR__) . '/website/api.php');
$check(strpos($canonicalApiSource, "REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'") !== false, 'WEB CONSULTANT API handles CORS preflight before application dispatch');
$check(strpos($canonicalApiSource, "origin_not_allowed") !== false, 'WEB CONSULTANT API rejects disallowed cross-origin callers');
$check(strpos($legacyApiSource, "'/web-consultant/api.php'") !== false, 'legacy WEBSITE API delegates to canonical web consultant API');

if ($failures) {
    fwrite(STDERR, "Website origin policy regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEBSITE ORIGIN POLICY REGRESSION PASSED\n";
