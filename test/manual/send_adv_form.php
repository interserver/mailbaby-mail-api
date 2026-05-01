<?php
/**
 * advsend smoke test using application/x-www-form-urlencoded.
 *
 * Run: MAILBABY_API_KEY=... php test/manual/send_adv_form.php
 */
require_once __DIR__ . '/_helper.php';

$postData = http_build_query([
    'subject'     => 'advsend form smoke test ' . date('c'),
    'body'        => 'manual smoke test',
    'from[email]' => 'detain@interserver.net',
    'from[name]'  => 'Joe Huss',
    'to[0][email]'=> 'detain@interserver.net',
    'to[0][name]' => 'Joe Huss',
    'id'          => getenv('MAILBABY_MAIL_ID') ?: '5658',
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => mailbaby_base_url() . '/mail/advsend',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'X-API-KEY: ' . mailbaby_api_key(),
    ],
]);
$response = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $code\n";
if ($err) {
    fwrite(STDERR, "cURL error: $err\n");
    exit(1);
}
echo $response, PHP_EOL;
