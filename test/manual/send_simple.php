<?php
/**
 * Simple send via the official PHP SDK (interserver/mailbaby-client-php).
 *
 * Run: MAILBABY_API_KEY=... php test/manual/send_simple.php
 */
require_once __DIR__ . '/_helper.php';

if (!class_exists(\Interserver\Mailbaby\Configuration::class)) {
    fwrite(STDERR, "Install interserver/mailbaby-client-php to use this script (composer require --dev interserver/mailbaby-client-php).\n");
    exit(1);
}

$config = \Interserver\Mailbaby\Configuration::getDefaultConfiguration()
    ->setApiKey('X-API-KEY', mailbaby_api_key());

$apiInstance = new \Interserver\Mailbaby\Api\DefaultApi(new \GuzzleHttp\Client(), $config);

$to = $argv[1] ?? 'detain@interserver.net';
$from = $argv[2] ?? 'detain@interserver.net';
$subject = 'manual smoke test ' . date('c');
$body = 'This is a manual smoke-test email.';

try {
    $result = $apiInstance->sendMail($to, $from, $subject, $body);
    var_export($result);
    echo PHP_EOL;
} catch (\Throwable $e) {
    echo 'Exception: ', $e->getMessage(), PHP_EOL;
    exit(1);
}
