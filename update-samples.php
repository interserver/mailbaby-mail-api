<?php

declare(strict_types=1);

$buildOpenApi = true;
$buildSwagger = true;
$runCmds = true;
$showCmds = true;
$removeJars = false;
$onlyLangs = [];
//$onlyLangs = ['php','html2'];
$cmds = [];
$cats = ['client' => [], 'documentation' => []];

const OPENAPI_FALLBACK_VERSION = '7.20.0';
const SWAGGER_FALLBACK_VERSION = '3.0.78';

$rootDir = __DIR__;
$samplesDir = $rootDir . '/mailbaby-api-samples';
$specLocal = $rootDir . '/public/spec/openapi.yaml';
$specRemote = 'https://raw.githubusercontent.com/interserver/mailbaby-mail-api/master/public/spec/openapi.yaml';
$spec = file_exists($specLocal) ? $specLocal : $specRemote;

function runCommand(string $cmd, bool $runCmds): string
{
    if (!$runCmds) {
        return '';
    }

    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        echo "Command failed ($code): $cmd\n";
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
    return implode(PHP_EOL, $output);
}

function ensureDir(string $path): void
{
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
}

function readJson(string $text): ?array
{
    $data = json_decode($text, true);
    return is_array($data) ? $data : null;
}

function fetchMavenLatest(string $metadataUrl, string $fallbackVersion): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $xml = @file_get_contents($metadataUrl, false, $ctx);
    if ($xml === false) {
        return $fallbackVersion;
    }
    if (!preg_match('/<latest>([^<]+)<\/latest>/i', $xml, $m) && !preg_match('/<release>([^<]+)<\/release>/i', $xml, $m)) {
        return $fallbackVersion;
    }
    $version = trim($m[1]);
    return $version !== '' ? $version : $fallbackVersion;
}

function parseOpenApiLangs(string $listOutput): array
{
    $cats = [];
    if (preg_match_all('/^([A-Za-z][A-Za-z\s\/-]+?)\s+generators:\s*\R((?:\s+-\s+\S+.*\R)+)/m', $listOutput, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $name = strtolower(trim($block[1]));
            if (preg_match_all('/^\s+-\s+(\S+)\s/m', $block[2], $langs)) {
                $cats[$name] = array_values(array_unique(array_map('trim', $langs[1])));
            }
        }
    }
    $cats['client'] = $cats['client'] ?? [];
    $cats['documentation'] = $cats['documentation'] ?? [];
    return $cats;
}

function parseSwaggerLangs(string $langsOutput): array
{
    if (!preg_match('/\[([^\]]+)\]/', $langsOutput, $m)) {
        return [];
    }
    $langs = array_map('trim', explode(',', $m[1]));
    $langs = array_filter($langs, static function ($lang) {
        return $lang !== '';
    });
    return array_values(array_unique($langs));
}

function normalizeProjectName(string $lang): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $lang), '-'));
    return $slug !== '' ? $slug : 'client';
}

function saveJsonFile(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function ensureSwaggerConfigFile(string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/swagger-config/' . $lang . '.json';
    if (file_exists($file)) {
        return;
    }
    $project = normalizeProjectName($lang);
    saveJsonFile($file, [
        'sortParamsByRequiredFlag' => 'true',
        'ensureUniqueParams' => 'true',
        'allowUnicodeIdentifiers' => 'true',
        'hideGenerationTimestamp' => 'true',
        'artifactVersion' => '1.0.0',
        'packageName' => 'mailbaby-client-' . $project,
        'gitUserId' => 'interserver',
        'gitRepoId' => 'mailbaby-client-' . $project,
    ]);
}

function ensureSwaggerOptionsFile(string $rootDir, string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/swagger-options/' . $lang . '.json';
    if (file_exists($file)) {
        return;
    }

    $apiUrl = 'https://generator.swagger.io/api/gen/clients/' . rawurlencode($lang);
    $raw = @shell_exec('curl -fsSL ' . escapeshellarg($apiUrl));
    $json = is_string($raw) ? readJson($raw) : null;
    if (is_array($json)) {
        saveJsonFile($file, $json);
        return;
    }

    $cmd = 'cd ' . escapeshellarg($rootDir) . ' && java -jar swagger-codegen-cli.jar config-help -l ' . escapeshellarg($lang);
    $helpText = runCommand($cmd, true);
    saveJsonFile($file, [
        'language' => $lang,
        'source' => 'swagger-codegen-cli config-help fallback',
        'configHelp' => $helpText,
    ]);
}

function ensureOpenApiConfigFile(string $rootDir, string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/openapi-config/' . $lang . '.yaml';
    if (file_exists($file)) {
        return;
    }
    $cmd = 'cd ' . escapeshellarg($samplesDir) . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh') . ' config-help -g ' . escapeshellarg($lang) . ' -f yamlsample > ' . escapeshellarg('openapi-config/' . $lang . '.yaml');
    runCommand($cmd, true);
}

echo "Using spec: {$spec}\n";
echo "Grabbing the samples repo\n";
if (!file_exists($samplesDir)) {
    passthru('cd ' . escapeshellarg($rootDir) . ' && git clone git@github.com:interserver/mailbaby-api-samples.git && cp -f .git/hooks/commit-msg mailbaby-api-samples/.git/hooks');
} else {
    passthru('cd ' . escapeshellarg($samplesDir) . ' && git pull --all');
}

if ($buildOpenApi === true) {
    echo "Determining latest OpenAPI Generator jar\n";
    $openApiVersion = fetchMavenLatest(
        'https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/maven-metadata.xml',
        OPENAPI_FALLBACK_VERSION
    );
    $openApiJarUrl = 'https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/' . $openApiVersion . '/openapi-generator-cli-' . $openApiVersion . '.jar';
    echo "Grabbing OpenAPI Generator jar {$openApiJarUrl}\n";
    passthru('cd ' . escapeshellarg($rootDir) . ' && wget -q ' . escapeshellarg($openApiJarUrl) . ' -O openapi-generator-cli.jar');

    echo "Generating a list of OpenAPI Generator clients we can generate\n";
    $out = runCommand('cd ' . escapeshellarg($rootDir) . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh') . ' list', true);

    echo "Parsing OpenAPI Generator clients list\n";
    $cats = parseOpenApiLangs($out);

    echo "Generating OpenAPI Generator samples\n";
    foreach (['output', 'config'] as $dir) {
        ensureDir($samplesDir . '/openapi-' . $dir);
    }

    foreach (['client', 'documentation'] as $type) {
        foreach ($cats[$type] as $idx => $lang) {
            if ($lang === '' || (count($onlyLangs) > 0 && !in_array($lang, $onlyLangs, true))) {
                continue;
            }
            echo "[$idx] OpenAPI {$type} Generator Language: $lang\n";
            ensureOpenApiConfigFile($rootDir, $samplesDir, $lang);

            $cmd = 'cd ' . escapeshellarg($samplesDir)
                . ' && rm -rf ' . escapeshellarg('openapi-' . $type . '/' . $lang)
                . ' && mkdir -p ' . escapeshellarg('openapi-' . $type . '/' . $lang)
                . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh')
                . ' generate --enable-post-process-file'
                . ' --additional-properties=modelPropertyNaming=original'
                . ' -i ' . escapeshellarg($spec)
                . ' -g ' . escapeshellarg($lang)
                . ' -o ' . escapeshellarg('openapi-' . $type . '/' . $lang . '/')
                . (file_exists($samplesDir . '/openapi-config/' . $lang . '.yaml') ? ' -c ' . escapeshellarg('openapi-config/' . $lang . '.yaml') : '')
                . ' 2>&1 | tee ' . escapeshellarg('openapi-output/' . $type . '-' . $lang . '.txt') . ';';

            $cmds[] = $cmd;
            if ($showCmds == true) {
                echo $cmd . PHP_EOL;
            }
            if ($runCmds == true) {
                passthru($cmd);
            }
        }
    }
}

if ($buildSwagger === true) {
    echo "Determining latest Swagger Generator jar\n";
    $swaggerVersion = fetchMavenLatest(
        'https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/maven-metadata.xml',
        SWAGGER_FALLBACK_VERSION
    );
    $swaggerJarUrl = 'https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/' . $swaggerVersion . '/swagger-codegen-cli-' . $swaggerVersion . '.jar';
    echo "Grabbing latest Swagger Generator jar {$swaggerJarUrl}\n";
    passthru('cd ' . escapeshellarg($rootDir) . ' && wget -q ' . escapeshellarg($swaggerJarUrl) . ' -O swagger-codegen-cli.jar');

    echo "Generating and parsing a list of Swagger Generator clients we can generate\n";
    $langsOutput = runCommand('cd ' . escapeshellarg($rootDir) . ' && java -jar swagger-codegen-cli.jar langs', true);
    $langs = parseSwaggerLangs($langsOutput);

    foreach (['output', 'options', 'config'] as $dir) {
        ensureDir($samplesDir . '/swagger-' . $dir);
    }

    foreach ($langs as $idx => $lang) {
        if ($lang === '' || (count($onlyLangs) > 0 && !in_array($lang, $onlyLangs, true))) {
            continue;
        }

        $type = 'client';
        if (isset($cats['documentation']) && in_array($lang, $cats['documentation'], true)) {
            $type = 'documentation';
        }

        echo "[$idx] Swagger {$type} Generator Language: $lang\n";
        ensureSwaggerConfigFile($samplesDir, $lang);
        ensureSwaggerOptionsFile($rootDir, $samplesDir, $lang);

        $cmd = 'cd ' . escapeshellarg($samplesDir)
            . ' && rm -rf ' . escapeshellarg('swagger-' . $type . '/' . $lang)
            . ' && mkdir -p ' . escapeshellarg('swagger-' . $type . '/' . $lang)
            . ' && java -jar ' . escapeshellarg($rootDir . '/swagger-codegen-cli.jar')
            . ' generate -l ' . escapeshellarg($lang)
            . ' --additional-properties modelPropertyNaming=original'
            . ' -i ' . escapeshellarg($spec)
            . ' -o ' . escapeshellarg('swagger-' . $type . '/' . $lang . '/')
            . (file_exists($samplesDir . '/swagger-config/' . $lang . '.json') ? ' -c ' . escapeshellarg('swagger-config/' . $lang . '.json') : '')
            . ' 2>&1 | tee ' . escapeshellarg('swagger-output/' . $type . '-' . $lang . '.txt') . ';';

        $cmds[] = $cmd;
        if ($showCmds == true) {
            echo $cmd . PHP_EOL;
        }
        if ($runCmds == true) {
            passthru($cmd);
        }
    }
}

echo "Committing updated samples\n";
//passthru('cd '.__DIR__.'/mailbaby-api-samples && git add -A && git commit -a -m "Updated API samples" && git push --all');

echo "Cleaning up\n";
if ($removeJars == true) {
    if ($buildOpenApi === true) {
        passthru('cd ' . escapeshellarg($rootDir) . ' && rm -f openapi-generator-cli.jar openapitools.json');
    }
    if ($buildSwagger === true) {
        passthru('cd ' . escapeshellarg($rootDir) . ' && rm -f swagger-codegen-cli.jar');
    }
}

file_put_contents('update-samples.cmds', implode(PHP_EOL, $cmds) . PHP_EOL);
echo "done!\n";

