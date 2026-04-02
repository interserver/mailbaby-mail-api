<?php

declare(strict_types=1);

$buildOpenApi = true;
$buildSwagger = true;
$runCmds      = true;
$showCmds     = true;
$removeJars   = true;
$onlyLangs    = [];
//$onlyLangs  = ['php', 'html2'];
$cmds = [];
$cats = ['client' => [], 'documentation' => []];

const OPENAPI_FALLBACK_VERSION = '7.21.0';
const SWAGGER_FALLBACK_VERSION = '3.0.78';

$rootDir    = __DIR__;
$samplesDir = $rootDir . '/mailbaby-api-samples';
$specLocal  = $rootDir . '/public/spec/openapi.yaml';
$specRemote = 'https://raw.githubusercontent.com/interserver/mailbaby-mail-api/master/public/spec/openapi.yaml';
$spec       = file_exists($specLocal) ? $specLocal : $specRemote;

// ── Helpers ──────────────────────────────────────────────────────────────────

function runCommand(string $cmd, bool $runCmds): string
{
    if (!$runCmds) {
        return '';
    }
    $output = [];
    $code   = 0;
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ($code): $cmd\n");
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
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
        'http' => ['timeout' => 15, 'ignore_errors' => true],
    ]);
    $xml = @file_get_contents($metadataUrl, false, $ctx);
    if ($xml === false) {
        return $fallbackVersion;
    }
    if (!preg_match('/<release>([^<]+)<\/release>/i', $xml, $m)
        && !preg_match('/<latest>([^<]+)<\/latest>/i', $xml, $m)) {
        return $fallbackVersion;
    }
    $version = trim($m[1]);
    return $version !== '' ? $version : $fallbackVersion;
}

function parseOpenApiLangs(string $listOutput): array
{
    $cats = [];
    if (preg_match_all(
        '/^([A-Za-z][A-Za-z\s\/-]+?)\s+generators:\s*\R((?:\s+-\s+\S+.*\R)+)/m',
        $listOutput, $blocks, PREG_SET_ORDER
    )) {
        foreach ($blocks as $block) {
            $name = strtolower(trim($block[1]));
            if (preg_match_all('/^\s+-\s+(\S+)\s/m', $block[2], $langs)) {
                $cats[$name] = array_values(array_unique(array_map('trim', $langs[1])));
            }
        }
    }
    $cats['client']        = $cats['client'] ?? [];
    $cats['documentation'] = $cats['documentation'] ?? [];
    return $cats;
}

function parseSwaggerLangs(string $langsOutput): array
{
    if (!preg_match('/\[([^\]]+)\]/', $langsOutput, $m)) {
        return [];
    }
    $langs = array_map('trim', explode(',', $m[1]));
    $langs = array_filter($langs, static fn($l) => $l !== '');
    return array_values(array_unique($langs));
}

function normalizeProjectName(string $lang): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $lang), '-'));
    return $slug !== '' ? $slug : 'client';
}

function saveJsonFile(string $file, array $data): void
{
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
}

/**
 * Derive a safe C#/package name from the language slug.
 * Replaces hyphens and dots with nothing, applies PascalCase so the result
 * is a valid identifier and does not contain '--' when embedded in XML.
 */
function safePackageName(string $lang): string
{
    $parts = preg_split('/[-_. ]+/', $lang);
    return 'MailBaby' . implode('', array_map('ucfirst', $parts));
}

function ensureSwaggerConfigFile(string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/swagger-config/' . $lang . '.json';
    if (file_exists($file)) {
        return;
    }
    $project    = normalizeProjectName($lang);
    $pkgName    = safePackageName($lang);
    $data = [
        'sortParamsByRequiredFlag'  => true,
        'ensureUniqueParams'        => true,
        'allowUnicodeIdentifiers'   => true,
        'hideGenerationTimestamp'   => true,
        'artifactVersion'           => '1.0.0',
        'packageName'               => $pkgName,
        'gitUserId'                 => 'interserver',
        'gitRepoId'                 => 'mailbaby-client-' . $project,
    ];
    saveJsonFile($file, $data);
}

function ensureSwaggerOptionsFile(string $rootDir, string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/swagger-options/' . $lang . '.json';
    if (file_exists($file)) {
        return;
    }
    $apiUrl = 'https://generator.swagger.io/api/gen/clients/' . rawurlencode($lang);
    $raw    = @shell_exec('curl -fsSL ' . escapeshellarg($apiUrl));
    $json   = is_string($raw) ? readJson($raw) : null;
    if (is_array($json)) {
        saveJsonFile($file, $json);
        return;
    }
    $cmd      = 'cd ' . escapeshellarg($rootDir)
        . ' && java -jar swagger-codegen-cli.jar config-help -l ' . escapeshellarg($lang);
    $helpText = runCommand($cmd, true);
    saveJsonFile($file, [
        'language'   => $lang,
        'source'     => 'swagger-codegen-cli config-help fallback',
        'configHelp' => $helpText,
    ]);
}

function ensureOpenApiConfigFile(string $rootDir, string $samplesDir, string $lang): void
{
    $file = $samplesDir . '/openapi-config/' . $lang . '.yaml';
    if (file_exists($file)) {
        return;
    }
    $cmd = 'cd ' . escapeshellarg($samplesDir)
        . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh')
        . ' config-help -g ' . escapeshellarg($lang)
        . ' -f yamlsample > ' . escapeshellarg('openapi-config/' . $lang . '.yaml');
    runCommand($cmd, true);
}

/**
 * Write (or overwrite) the thin shell wrapper that invokes the JAR.
 * The wrapper adds memory tuning and suppresses JVM noise on stderr.
 */
function writeOpenApiCliWrapper(string $rootDir): void
{
    $wrapper = $rootDir . '/openapi-generator-cli.sh';
    $jar     = $rootDir . '/openapi-generator-cli.jar';
    $content = <<<SH
#!/usr/bin/env bash
# Auto-generated wrapper – do not edit by hand; update-samples.php recreates this.
exec java \
  -Xmx1g \
  -Dlog.level=warn \
  -jar {$jar} "\$@"
SH;
    file_put_contents($wrapper, $content);
    chmod($wrapper, 0755);
    echo "Wrote {$wrapper}\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

echo "Using spec: {$spec}\n";
echo "Grabbing the samples repo\n";
if (!file_exists($samplesDir)) {
    passthru('cd ' . escapeshellarg($rootDir)
        . ' && git clone git@github.com:interserver/mailbaby-api-samples.git'
        . ' && cp -f .git/hooks/commit-msg mailbaby-api-samples/.git/hooks');
} else {
    passthru('cd ' . escapeshellarg($samplesDir) . ' && git pull --all');
}

// ── OpenAPI Generator ────────────────────────────────────────────────────────
if ($buildOpenApi === true) {
    echo "Determining latest OpenAPI Generator jar\n";
    $openApiVersion = fetchMavenLatest(
        'https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/maven-metadata.xml',
        OPENAPI_FALLBACK_VERSION
    );
    $openApiJarUrl = sprintf(
        'https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/%s/openapi-generator-cli-%s.jar',
        $openApiVersion, $openApiVersion
    );
    echo "Grabbing OpenAPI Generator jar {$openApiJarUrl}\n";
    passthru('cd ' . escapeshellarg($rootDir)
        . ' && wget -q ' . escapeshellarg($openApiJarUrl) . ' -O openapi-generator-cli.jar');

    // Always recreate the wrapper so it points at the freshly downloaded jar.
    //writeOpenApiCliWrapper($rootDir);

    echo "Generating a list of OpenAPI Generator clients\n";
    $out  = runCommand(
        'cd ' . escapeshellarg($rootDir)
        . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh') . ' list',
        true
    );

    echo "Parsing OpenAPI Generator clients list\n";
    $cats = parseOpenApiLangs($out);

    foreach (['output', 'config'] as $dir) {
        ensureDir($samplesDir . '/openapi-' . $dir);
    }

    foreach (['client', 'documentation'] as $type) {
        foreach ($cats[$type] as $idx => $lang) {
            if ($lang === '' || (count($onlyLangs) > 0 && !in_array($lang, $onlyLangs, true))) {
                continue;
            }
            echo "[$idx] OpenAPI {$type}: $lang\n";
            ensureOpenApiConfigFile($rootDir, $samplesDir, $lang);

            $cfgFlag = file_exists($samplesDir . '/openapi-config/' . $lang . '.yaml')
                ? ' -c ' . escapeshellarg('openapi-config/' . $lang . '.yaml')
                : '';

            $cmd = 'cd ' . escapeshellarg($samplesDir)
                . ' && rm -rf ' . escapeshellarg('openapi-' . $type . '/' . $lang)
                . ' && mkdir -p ' . escapeshellarg('openapi-' . $type . '/' . $lang)
                . ' && ' . escapeshellarg($rootDir . '/openapi-generator-cli.sh')
                . ' generate'
                . ' --enable-post-process-file'
                . ' --additional-properties=modelPropertyNaming=original'
                . ' --additional-properties=generateAliasAsModel=true'
                . ' -i ' . escapeshellarg($spec)
                . ' -g ' . escapeshellarg($lang)
                . ' -o ' . escapeshellarg('openapi-' . $type . '/' . $lang . '/')
                . $cfgFlag
                . ' 2>&1 | tee ' . escapeshellarg('openapi-output/' . $type . '-' . $lang . '.txt') . ';';

            $cmds[] = $cmd;
            if ($showCmds) {
                echo $cmd . PHP_EOL;
            }
            if ($runCmds) {
                passthru($cmd);
            }
        }
    }
}

// ── Swagger Codegen ──────────────────────────────────────────────────────────
if ($buildSwagger === true) {
    echo "Determining latest Swagger Codegen jar\n";
    $swaggerVersion = fetchMavenLatest(
        'https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/maven-metadata.xml',
        SWAGGER_FALLBACK_VERSION
    );
    $swaggerJarUrl = sprintf(
        'https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/%s/swagger-codegen-cli-%s.jar',
        $swaggerVersion, $swaggerVersion
    );
    echo "Grabbing Swagger Codegen jar {$swaggerJarUrl}\n";
    passthru('cd ' . escapeshellarg($rootDir)
        . ' && wget -q ' . escapeshellarg($swaggerJarUrl) . ' -O swagger-codegen-cli.jar');

    echo "Fetching Swagger lang list\n";
    $langsOutput = runCommand(
        'cd ' . escapeshellarg($rootDir)
        . ' && java -jar swagger-codegen-cli.jar langs | grep languages:',
        true
    );
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

        echo "[$idx] Swagger {$type}: $lang\n";
        ensureSwaggerConfigFile($samplesDir, $lang);
        ensureSwaggerOptionsFile($rootDir, $samplesDir, $lang);

        $cfgFlag = file_exists($samplesDir . '/swagger-config/' . $lang . '.json')
            ? ' -c ' . escapeshellarg('swagger-config/' . $lang . '.json')
            : '';

        $cmd = 'cd ' . escapeshellarg($samplesDir)
            . ' && rm -rf ' . escapeshellarg('swagger-' . $type . '/' . $lang)
            . ' && mkdir -p ' . escapeshellarg('swagger-' . $type . '/' . $lang)
            . ' && java -Xmx1g -jar ' . escapeshellarg($rootDir . '/swagger-codegen-cli.jar')
            . ' generate'
            . ' -l ' . escapeshellarg($lang)
            . ' --additional-properties modelPropertyNaming=original'
            . ' -i ' . escapeshellarg($spec)
            . ' -o ' . escapeshellarg('swagger-' . $type . '/' . $lang . '/')
            . $cfgFlag
            . ' 2>&1 | tee ' . escapeshellarg('swagger-output/' . $type . '-' . $lang . '.txt') . ';';

        $cmds[] = $cmd;
        if ($showCmds) {
            echo $cmd . PHP_EOL;
        }
        if ($runCmds) {
            passthru($cmd);
        }
    }
}

echo "Committing updated samples\n";
//passthru('cd '.__DIR__.'/mailbaby-api-samples && git add -A && git commit -a -m "Updated API samples" && git push --all');

echo "Cleaning up jars\n";
if ($removeJars === true) {
    if ($buildOpenApi) {
        passthru('cd ' . escapeshellarg($rootDir) . ' && rm -f openapi-generator-cli.jar openapitools.json');
    }
    if ($buildSwagger) {
        passthru('cd ' . escapeshellarg($rootDir) . ' && rm -f swagger-codegen-cli.jar');
    }
}

$cmdsFile = $rootDir . '/update-samples.cmds';
file_put_contents($cmdsFile, implode(PHP_EOL, $cmds) . PHP_EOL);
echo "Commands saved to {$cmdsFile}\n";
echo "done!\n";
