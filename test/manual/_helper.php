<?php
/**
 * Shared helpers for manual smoke-test scripts.
 *
 * Reads MAILBABY_API_KEY and MAILBABY_BASE_URL from the environment so
 * we never commit credentials. Falls back to .env in the repo root when
 * vlucas/phpdotenv is installed (it is, via webman).
 */

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/../../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
}

function mailbaby_api_key(): string
{
    $key = getenv('MAILBABY_API_KEY') ?: ($_ENV['MAILBABY_API_KEY'] ?? '');
    if ($key === '') {
        fwrite(STDERR, "MAILBABY_API_KEY is not set. Export it or put it in .env before running.\n");
        exit(1);
    }
    return $key;
}

function mailbaby_base_url(): string
{
    return rtrim(getenv('MAILBABY_BASE_URL') ?: ($_ENV['MAILBABY_BASE_URL'] ?? 'https://api.mailbaby.net'), '/');
}

function mailbaby_post_json(string $path, array $payload): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => mailbaby_base_url() . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-KEY: ' . mailbaby_api_key(),
        ],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}
