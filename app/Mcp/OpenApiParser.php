<?php
declare(strict_types=1);

namespace app\Mcp;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses an OpenAPI 3.x YAML spec into MCP tool definitions.
 *
 * One MCP tool is produced per HTTP operation. The first ~900 chars of an
 * operation's combined summary+description are surfaced to the AI client as
 * the tool description (truncated at a sentence boundary when possible).
 */
class OpenApiParser
{
    /** Max characters of description to expose to MCP clients. */
    private const DESCRIPTION_LIMIT = 900;

    private array $spec = [];
    private string $cacheDir;

    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir();
    }

    /**
     * @return array<int, array{name:string,description:string,httpMethod:string,path:string,inputSchema:array,pathParams:string[],queryParams:string[],hasBody:bool,annotations:array,tag:string}>
     */
    public function parse(string $specFile): array
    {
        $cacheFile = $this->cacheDir . '/mcp_tools_' . md5($specFile) . '.php';
        if (file_exists($cacheFile) && filemtime($cacheFile) >= filemtime($specFile)) {
            return require $cacheFile;
        }

        $this->spec = Yaml::parseFile($specFile);
        $tools = $this->extractTools();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
        file_put_contents($cacheFile, "<?php\nreturn " . var_export($tools, true) . ";\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
        return $tools;
    }

    private function extractTools(): array
    {
        $tools = [];
        foreach ($this->spec['paths'] ?? [] as $path => $pathItem) {
            $sharedParams = $pathItem['parameters'] ?? [];
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (!isset($pathItem[$method])) continue;
                $tool = $this->buildTool((string)$path, $method, $pathItem[$method], $sharedParams);
                if ($tool !== null) $tools[] = $tool;
            }
        }
        return $tools;
    }

    private function buildTool(string $path, string $httpMethod, array $operation, array $sharedParams): ?array
    {
        $operationId = $operation['operationId'] ?? $this->generateOperationId($path, $httpMethod);
        $method = strtoupper($httpMethod);
        $tag = !empty($operation['tags'][0]) ? (string)$operation['tags'][0] : '';

        $summary = trim((string)($operation['summary'] ?? ''));
        $description = trim((string)($operation['description'] ?? ''));
        $combined = $summary;
        if ($description !== '' && $description !== $summary) {
            $combined .= ($combined !== '' ? "\n\n" : '') . $description;
        }
        if ($combined === '') {
            $combined = $method . ' ' . $path;
        }

        // Front-load the call signature so the model sees the verb+path even
        // when it has only scanned the first couple of lines of the description.
        $prefix = '[' . $method . ' ' . $path . ']';
        if ($tag !== '') {
            $prefix .= ' [' . $tag . ']';
        }
        $isDestructive = $this->isDestructive($method, $operationId);
        if ($isDestructive) {
            $prefix .= ' [DESTRUCTIVE]';
        }
        $combined = $prefix . ' ' . $combined;

        if (mb_strlen($combined) > self::DESCRIPTION_LIMIT) {
            $hard = mb_substr($combined, 0, self::DESCRIPTION_LIMIT);
            $cut = max(
                (int)mb_strrpos($hard, '. '),
                (int)mb_strrpos($hard, '? '),
                (int)mb_strrpos($hard, '! '),
                (int)mb_strrpos($hard, "\n\n")
            );
            $combined = ($cut > (self::DESCRIPTION_LIMIT * 0.75))
                ? mb_substr($combined, 0, $cut + 1)
                : (mb_substr($combined, 0, self::DESCRIPTION_LIMIT - 3) . '...');
        }

        // Walk parameters (path-level + operation-level) into JSON Schema.
        $allParams = array_merge($sharedParams, $operation['parameters'] ?? []);
        $pathParams = $queryParams = $properties = $required = [];

        foreach ($allParams as $param) {
            $param = $this->resolveRef($param);
            $name = $param['name'] ?? '';
            if ($name === '') continue;
            $schema = $this->resolveRef($param['schema'] ?? ['type' => 'string']);
            $propDef = $this->simplifySchema($schema);
            if (!empty($param['description'])) {
                $propDef['description'] = (string)$param['description'];
            }
            if (!empty($param['example'])) {
                $propDef['example'] = $param['example'];
            }
            $in = $param['in'] ?? 'query';
            if ($in === 'path') {
                $pathParams[] = $name;
                $required[] = $name;
            } elseif ($in === 'query') {
                $queryParams[] = $name;
                if (!empty($param['required'])) $required[] = $name;
            } else {
                continue;
            }
            $properties[$name] = $propDef;
        }

        // Body schema → flatten into top-level properties.
        $hasBody = false;
        $bodySchema = $this->extractRequestBodySchema($operation);
        if ($bodySchema !== null) {
            $hasBody = true;
            foreach (($bodySchema['properties'] ?? []) as $propName => $propDef) {
                $properties[$propName] = $this->simplifySchema($propDef);
            }
            foreach (($bodySchema['required'] ?? []) as $r) {
                $required[] = $r;
            }
        }

        $inputSchema = ['type' => 'object'];
        if (!empty($properties)) $inputSchema['properties'] = $properties;
        if (!empty($required))   $inputSchema['required']   = array_values(array_unique($required));

        $annotations = [
            'title'           => $summary !== '' ? $summary : $operationId,
            'readOnlyHint'    => $method === 'GET' && !$isDestructive,
            'destructiveHint' => $isDestructive,
            'idempotentHint'  => in_array($method, ['GET', 'PUT', 'DELETE'], true),
            'openWorldHint'   => true,
        ];

        return [
            'name'        => $operationId,
            'description' => $combined,
            'httpMethod'  => $method,
            'path'        => $path,
            'inputSchema' => $inputSchema,
            'pathParams'  => $pathParams,
            'queryParams' => $queryParams,
            'hasBody'     => $hasBody,
            'annotations' => $annotations,
            'tag'         => $tag,
        ];
    }

    private function extractRequestBodySchema(array $operation): ?array
    {
        if (!isset($operation['requestBody'])) return null;
        $body = $this->resolveRef($operation['requestBody']);
        $content = $body['content'] ?? [];
        foreach (['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'] as $media) {
            if (isset($content[$media]['schema'])) {
                return $this->resolveRef($content[$media]['schema']);
            }
        }
        return null;
    }

    private function resolveRef(array $item): array
    {
        if (!isset($item['$ref'])) return $item;
        $ref = (string)$item['$ref'];
        if (!str_starts_with($ref, '#/')) return $item;
        $parts = explode('/', ltrim($ref, '#/'));
        $resolved = $this->spec;
        foreach ($parts as $p) {
            $p = str_replace(['~1', '~0'], ['/', '~'], $p);
            if (!is_array($resolved) || !array_key_exists($p, $resolved)) return $item;
            $resolved = $resolved[$p];
        }
        return is_array($resolved) ? $this->resolveRef($resolved) : $item;
    }

    private function simplifySchema(array $schema): array
    {
        $schema = $this->resolveRef($schema);
        $out = [];
        foreach (['type', 'description', 'enum', 'format', 'minimum', 'maximum',
                  'minLength', 'maxLength', 'pattern', 'default', 'example'] as $k) {
            if (array_key_exists($k, $schema)) $out[$k] = $schema[$k];
        }
        if (!empty($schema['nullable'])) $out['nullable'] = true;
        if (($schema['type'] ?? '') === 'object' && isset($schema['properties'])) {
            $out['properties'] = [];
            foreach ($schema['properties'] as $k => $v) {
                $out['properties'][$k] = $this->simplifySchema($v);
            }
            if (isset($schema['required'])) $out['required'] = $schema['required'];
        }
        if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
            $out['items'] = $this->simplifySchema($schema['items']);
        }
        // oneOf/allOf/anyOf collapse: surface as raw object so models can pick.
        foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
            if (!empty($schema[$combinator]) && is_array($schema[$combinator])) {
                $out[$combinator] = array_map([$this, 'simplifySchema'], $schema[$combinator]);
            }
        }
        return $out ?: ['type' => 'string'];
    }

    private function isDestructive(string $method, string $operationId): bool
    {
        if ($method === 'DELETE') return true;
        $lower = strtolower($operationId);
        foreach (['delete', 'remove', 'cancel', 'destroy', 'purge', 'wipe', 'delist'] as $verb) {
            if (str_starts_with($lower, $verb)) return true;
        }
        return false;
    }

    private function generateOperationId(string $path, string $method): string
    {
        $name = strtolower($method);
        foreach (array_filter(explode('/', $path)) as $part) {
            if (str_starts_with($part, '{')) continue;
            $name .= '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $part);
        }
        return $name;
    }
}
