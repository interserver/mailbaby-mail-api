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
 * MCP server controller. Speaks the Streamable HTTP transport defined by
 * the Model Context Protocol, advertising every operation in the OpenAPI
 * spec as an MCP tool. Each tool call is proxied back to this same API
 * with the caller's X-API-KEY forwarded.
 */
class Mcp extends BaseController
{
    private const SERVER_NAME    = 'MailBaby Mail API';
    private const SERVER_VERSION = '1.0.0';

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

        // Tools proxy back to this same Webman instance.
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

    public function serverCard(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        return $this->jsonResponse([
            'schema_version' => '2025-03-26',
            'name'           => self::SERVER_NAME,
            'version'        => self::SERVER_VERSION,
            'description'    => 'MCP server backed by the MailBaby Mail Delivery API. '
                . 'Every OpenAPI operation is exposed as an MCP tool. Authenticate with X-API-KEY.',
            'transport'      => [
                'type'    => 'streamable_http',
                'url'     => $base . '/mcp',
                'methods' => ['POST', 'GET', 'DELETE', 'OPTIONS'],
            ],
            'authentication' => [
                'schemes' => [
                    [
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-API-KEY',
                        'description' => 'MailBaby API key from https://my.interserver.net/account_security',
                    ],
                ],
            ],
            'capabilities' => [
                'tools'     => ['listChanged' => false],
                'resources' => false,
                'prompts'   => false,
            ],
            'documentation' => [
                'openapi' => $base . '/spec/openapi.yaml',
                'website' => 'https://www.mail.baby/',
                'docs'    => $base . '/swagger-ui.html',
            ],
        ]);
    }

    public function oauthProtectedResource(Request $request): Response
    {
        $base = $this->resolveBaseUrl($request);
        return $this->jsonResponse([
            'resource'                 => $base . '/mcp',
            'authorization_servers'    => [],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => self::SERVER_NAME,
            'resource_documentation'   => $base . '/spec/openapi.yaml',
            // No OAuth backend yet; this advertises that the resource is
            // protected and where to learn about it.
            'authentication_methods' => [
                ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-KEY'],
            ],
        ]);
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
}
