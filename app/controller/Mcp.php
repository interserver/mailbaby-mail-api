<?php
declare(strict_types=1);

namespace app\controller;

use app\Mcp\Bridge;
use app\Mcp\McpServerFactory;
use app\Mcp\OpenApiParser;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use support\Request;
use support\Response;

/**
 * MCP server controller plus the agent-discovery endpoints under /.well-known/.
 *
 * - handle(): the Streamable-HTTP MCP endpoint at /mcp.
 * - serverCard(): SEP-1649 MCP Server Card (also serves the SEP-1960 alias).
 * - oauthProtectedResource(): RFC 9728 metadata for /mcp.
 * - webmcpManifest(): WebMCP-style manifest (tools list mapped from OpenAPI).
 * - agentSkillsIndex(): agentskills.io 0.2.0 discovery index.
 * - agentSkill(): serves an individual SKILL.md from app/Mcp/Skills/.
 */
class Mcp extends BaseController
{
    private const SERVER_NAME    = 'MailBaby Mail API';
    private const SERVER_VERSION = '1.0.0';
    private const PROTOCOL_VER   = '2025-06-18';

    public function handle(Request $request): Response
    {
        $apiKey = (string)$request->header('x-api-key', '');
        if ($apiKey === '' && $request->method() !== 'OPTIONS') {
            return $this->oauthChallenge($request);
        }

        $specFile = base_path() . '/public/spec/openapi.yaml';
        $cacheDir = runtime_path() . '/mcp/cache';
        $sessionDir = runtime_path() . '/mcp/sessions';
        foreach ([$cacheDir, $sessionDir] as $dir) {
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
        }

        $parser = new OpenApiParser($cacheDir);
        $toolDefs = $parser->parse($specFile);

        $apiBaseUrl = $this->resolveBaseUrl($request);
        $factory = new McpServerFactory($apiBaseUrl, $apiKey);
        $server = $factory->build(self::SERVER_NAME, self::SERVER_VERSION, $toolDefs, new FileSessionStore($sessionDir));

        $psrRequest = Bridge::toPsr7($request);
        $transport = new StreamableHttpTransport($psrRequest);

        try {
            $psrResponse = $server->run($transport);
        } catch (\Throwable $e) {
            return $this->jsonErrorResponse('MCP transport error: ' . $e->getMessage(), 500);
        }
        return Bridge::fromPsr7($psrResponse);
    }

    /**
     * MCP Server Card per SEP-1649. Served at /.well-known/mcp/server-card.json
     * (canonical), /.well-known/mcp.json (SEP-1960 alias), and
     * /.well-known/mcp/server.json (kept for back-compat).
     */
    public function serverCard(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        $tools = $this->loadToolDefs();
        $toolEntries = array_map(static function (array $t): array {
            return [
                'name'        => $t['name'],
                'title'       => (string)($t['annotations']['title'] ?? $t['name']),
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
            ];
        }, $tools);

        $card = [
            '$schema'         => 'https://static.modelcontextprotocol.io/schemas/mcp-server-card/v1.json',
            'version'         => '1.0',
            'protocolVersion' => self::PROTOCOL_VER,
            'serverInfo'      => [
                'name'    => 'mailbaby-mail-api',
                'title'   => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'title'            => self::SERVER_NAME,
            'description'      => 'MCP server backed by the MailBaby Mail Delivery API. '
                . 'Every OpenAPI operation is exposed as an MCP tool. Authenticate with X-API-KEY.',
            'documentationUrl' => $base . '/spec/openapi.yaml',
            'iconUrl'          => $base . '/favicon.ico',
            'transport'        => [
                'type'     => 'streamable-http',
                'endpoint' => '/mcp',
                'url'      => $base . '/mcp',
                'methods'  => ['POST', 'GET', 'DELETE', 'OPTIONS'],
            ],
            'authentication'   => [
                'required' => true,
                'schemes'  => [
                    [
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-API-KEY',
                        'description' => 'MailBaby API key from https://my.interserver.net/account_security',
                    ],
                ],
            ],
            'capabilities'     => [
                'tools'     => ['listChanged' => false],
                'resources' => new \stdClass(),
                'prompts'   => new \stdClass(),
            ],
            'instructions'     => 'Authenticate every call with X-API-KEY. Use getMailOrders to '
                . 'discover your sending account ids before sending. Tools that mutate state '
                . '(deleteRule, delistBlock) carry the [DESTRUCTIVE] tag in their description.',
            'tools'            => $toolEntries,
        ];
        return $this->cachedJson($card, 3600);
    }

    /**
     * RFC 9728 protected-resource metadata for the /mcp endpoint.
     */
    public function oauthProtectedResource(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        return $this->cachedJson([
            'resource'                 => $base . '/mcp',
            'authorization_servers'    => [],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => self::SERVER_NAME,
            'resource_documentation'   => $base . '/spec/openapi.yaml',
            'authentication_methods'   => [
                ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-KEY'],
            ],
        ], 3600);
    }

    /**
     * WebMCP-style manifest. The format is still in flux upstream, so we
     * publish the field names the proposal currently calls out (`tools`
     * with name + description + inputSchema) plus a `mcpServers` block
     * pointing at our Streamable-HTTP endpoint, which is the convention
     * most current scanners expect.
     */
    public function webmcpManifest(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        $tools = $this->loadToolDefs();
        $toolEntries = array_map(static function (array $t): array {
            return [
                'name'        => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
            ];
        }, $tools);

        return $this->cachedJson([
            'name'        => 'mailbaby-mail-api',
            'title'       => self::SERVER_NAME,
            'description' => 'Send email and manage delivery for the Mail.Baby platform. '
                . 'Authenticate with X-API-KEY from my.interserver.net/account_security.',
            'version'     => self::SERVER_VERSION,
            'mcpServers'  => [
                'mailbaby' => [
                    'type'    => 'streamable-http',
                    'url'     => $base . '/mcp',
                    'headers' => ['X-API-KEY' => '${MAILBABY_API_KEY}'],
                ],
            ],
            'tools'       => $toolEntries,
        ], 3600);
    }

    /**
     * Agent Skills 0.2.0 discovery index.
     * https://schemas.agentskills.io/discovery/0.2.0/schema.json
     */
    public function agentSkillsIndex(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        $skills = [];
        foreach ($this->skillFiles() as $name => $path) {
            $skills[] = [
                'name'        => $name,
                'type'        => 'skill-md',
                'description' => $this->extractSkillDescription($path),
                'url'         => $base . '/.well-known/agent-skills/' . $name . '/SKILL.md',
                'digest'      => 'sha256:' . hash_file('sha256', $path),
            ];
        }
        return $this->cachedJson([
            '$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
            'skills'  => $skills,
        ], 3600);
    }

    /**
     * Serve one SKILL.md as text/markdown. Refuses anything that doesn't
     * match the strict slug shape so the lookup can't escape the skills dir.
     */
    public function agentSkill(Request $request, string $name): Response
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return $this->jsonErrorResponse('Invalid skill name.', 400);
        }
        $skills = $this->skillFiles();
        if (!isset($skills[$name])) {
            return $this->jsonErrorResponse('Skill not found.', 404);
        }
        $content = (string)file_get_contents($skills[$name]);
        return (new Response(200, [
            'Content-Type'           => 'text/markdown; charset=utf-8',
            'Cache-Control'          => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], $content));
    }

    private function oauthChallenge(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        $prm = $base . '/.well-known/oauth-protected-resource';
        $body = json_encode([
            'error'   => 'unauthorized',
            'message' => 'Authentication required. Send an X-API-KEY header from https://my.interserver.net/account_security.',
        ], JSON_UNESCAPED_UNICODE);
        return new Response(401, [
            'Content-Type'      => 'application/json',
            'WWW-Authenticate'  => 'Bearer realm="mailbaby-mcp", resource_metadata="' . $prm . '"',
        ], (string)$body);
    }

    private function resolveBaseUrl(Request $request): string
    {
        $proto = $request->header('x-forwarded-proto', null) ?: ($_SERVER['REQUEST_SCHEME'] ?? 'https');
        $host = $request->header('host') ?: 'api.mailbaby.net';
        return $proto . '://' . $host;
    }

    private function loadToolDefs(): array
    {
        $cacheDir = runtime_path() . '/mcp/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
        return (new OpenApiParser($cacheDir))->parse(base_path() . '/public/spec/openapi.yaml');
    }

    /**
     * @return array<string, string> map of skill slug → absolute file path
     */
    private function skillFiles(): array
    {
        $dir = base_path() . '/app/Mcp/Skills';
        $out = [];
        if (!is_dir($dir)) return $out;
        foreach ((array)glob($dir . '/*.md') as $path) {
            $slug = basename($path, '.md');
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug)) {
                $out[$slug] = $path;
            }
        }
        return $out;
    }

    private function extractSkillDescription(string $path): string
    {
        $content = (string)file_get_contents($path);
        if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $m)) {
            $front = $m[1];
            if (preg_match('/^description:\s*(.+?)(?:\n[a-zA-Z_-]+:|$)/sm', $front, $dm)) {
                return trim(preg_replace('/\s+/', ' ', $dm[1]));
            }
        }
        return '';
    }

    private function cachedJson(array $body, int $maxAge): Response
    {
        return (new Response(200, [
            'Content-Type'                 => 'application/json; charset=utf-8',
            'Cache-Control'                => 'public, max-age=' . $maxAge,
            'X-Content-Type-Options'       => 'nosniff',
            'Access-Control-Allow-Origin'  => '*',
        ], (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
    }
}
