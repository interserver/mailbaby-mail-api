<?php
/**
 * advsend smoke test with base64 attachments.
 *
 * Run: MAILBABY_API_KEY=... php test/manual/send_adv_attachment.php /path/to/file [more files...]
 */
require_once __DIR__ . '/_helper.php';

$files = array_slice($argv, 1);
if (empty($files)) {
    fwrite(STDERR, "Usage: php send_adv_attachment.php <file1> [file2 ...]\n");
    exit(1);
}

$attachments = [];
foreach ($files as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Not a file: $path\n");
        exit(1);
    }
    $attachments[] = [
        'filename' => basename($path),
        'data'     => base64_encode(file_get_contents($path)),
    ];
}

$result = mailbaby_post_json('/mail/advsend', [
    'subject'     => 'advsend attachment smoke test ' . date('c'),
    'body'        => 'manual smoke test with ' . count($attachments) . ' attachment(s)',
    'from'        => ['email' => 'detain@interserver.net', 'name' => 'The Man'],
    'to'          => [['email' => 'detain@gmail.com', 'name' => 'John Doe']],
    'attachments' => $attachments,
    'id'          => getenv('MAILBABY_MAIL_ID') ?: '5658',
]);

echo "HTTP {$result['code']}\n";
if ($result['error'] !== '') {
    fwrite(STDERR, "cURL error: {$result['error']}\n");
    exit(1);
}
echo $result['body'], PHP_EOL;
