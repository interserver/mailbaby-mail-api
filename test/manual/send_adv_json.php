<?php
/**
 * advsend smoke test using a JSON body.
 *
 * Run: MAILBABY_API_KEY=... php test/manual/send_adv_json.php
 */
require_once __DIR__ . '/_helper.php';

$result = mailbaby_post_json('/mail/advsend', [
    'subject' => 'advsend JSON smoke test ' . date('c'),
    'body'    => 'manual smoke test',
    'from'    => ['email' => 'detain@interserver.net', 'name' => 'Joe Huss'],
    'to'      => [['email' => 'detain@interserver.net', 'name' => 'Joe Huss']],
    'id'      => getenv('MAILBABY_MAIL_ID') ?: '5658',
]);

echo "HTTP {$result['code']}\n";
if ($result['error'] !== '') {
    fwrite(STDERR, "cURL error: {$result['error']}\n");
    exit(1);
}
echo $result['body'], PHP_EOL;
