<?php
declare(strict_types=1);

namespace app\Mcp;

use GuzzleHttp\Client as GuzzleClient;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionStoreInterface;

/**
 * Builds an MCP Server from parsed OpenAPI tool definitions.
 *
 * Each tool registered on the server proxies its arguments to the underlying
 * REST endpoint over HTTP, forwarding the inbound caller's API key.
 */
class McpServerFactory
{
    private string $apiBaseUrl;
    private string $apiKey;

    public function __construct(string $apiBaseUrl, string $apiKey = '')
    {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * @param array<int, array> $toolDefs Tool definitions from OpenApiParser::parse().
     */
    public function build(string $serverName, string $version, array $toolDefs, ?SessionStoreInterface $sessionStore = null): Server
    {
        $builder = Server::builder()
            ->setServerInfo($serverName, $version)
            ->setPaginationLimit(1000)
            ->setInstructions(
                "MailBaby is an SMTP relay service. Use the sending tools (send, advsend, rawsend) "
                . "to deliver outbound email through your provisioned mail order. Use the read tools "
                . "(getMailOrders, viewMailLog, getStats, getMailBlocks, getRules) before sending if "
                . "you need account context. Most calls auto-select your first active mail order when "
                . "the optional 'id' is omitted. The transaction id returned by sending tools matches "
                . "the 'mailid' filter on viewMailLog."
            );

        if ($sessionStore !== null) {
            $builder->setSession($sessionStore);
        }

        foreach ($toolDefs as $toolDef) {
            $annotations = new ToolAnnotations(
                title:           $toolDef['annotations']['title']           ?? null,
                readOnlyHint:    $toolDef['annotations']['readOnlyHint']    ?? null,
                destructiveHint: $toolDef['annotations']['destructiveHint'] ?? null,
                idempotentHint:  $toolDef['annotations']['idempotentHint']  ?? null,
                openWorldHint:   $toolDef['annotations']['openWorldHint']   ?? null,
            );
            $builder->addTool(
                handler:     $this->createHandler($toolDef),
                name:        $toolDef['name'],
                description: $toolDef['description'],
                annotations: $annotations,
                inputSchema: $toolDef['inputSchema'],
            );
        }

        return $builder->build();
    }

    private function createHandler(array $toolDef): \Closure
    {
        $httpMethod   = $toolDef['httpMethod'];
        $pathTemplate = $toolDef['path'];
        $pathParams   = $toolDef['pathParams'];
        $queryParams  = $toolDef['queryParams'];
        $hasBody      = $toolDef['hasBody'];
        $baseUrl      = $this->apiBaseUrl;
        $apiKey       = $this->apiKey;

        return function (RequestContext $ctx) use ($httpMethod, $pathTemplate, $pathParams, $queryParams, $hasBody, $baseUrl, $apiKey) {
            /** @var CallToolRequest $request */
            $request = $ctx->getRequest();
            $arguments = $request->arguments;

            $path = $pathTemplate;
            foreach ($pathParams as $p) {
                if (array_key_exists($p, $arguments)) {
                    $path = str_replace('{' . $p . '}', rawurlencode((string)$arguments[$p]), $path);
                }
            }

            $query = [];
            foreach ($queryParams as $p) {
                if (array_key_exists($p, $arguments)) {
                    $query[$p] = $arguments[$p];
                }
            }

            $body = null;
            if ($hasBody) {
                $reserved = array_merge($pathParams, $queryParams);
                $body = array_diff_key($arguments, array_flip($reserved));
            }

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'MailBaby-MCP/0.1',
            ];
            if ($apiKey !== '') {
                $headers['X-API-KEY'] = $apiKey;
            }
            $headers['X-Request-Id'] = sprintf('mcp-%s-%s', bin2hex(random_bytes(4)), date('His'));

            $client = new GuzzleClient([
                'base_uri'        => $baseUrl,
                'timeout'         => 30,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]);

            $options = ['headers' => $headers];
            if (!empty($query)) $options['query'] = $query;
            if (!empty($body))  $options['json']  = $body;

            try {
                $response = $client->request($httpMethod, $path, $options);
                $status = $response->getStatusCode();
                $raw = (string)$response->getBody();
                $decoded = json_decode($raw, true);

                if ($status >= 400) {
                    $msg = 'API returned HTTP ' . $status;
                    if (is_array($decoded) && !empty($decoded['message'])) {
                        $msg .= ': ' . $decoded['message'];
                    } elseif ($raw !== '') {
                        $msg .= ': ' . substr($raw, 0, 300);
                    }
                    return ['error' => $msg, 'status' => $status];
                }
                return $decoded ?? ['raw' => $raw];
            } catch (\Throwable $e) {
                return ['error' => 'API request failed: ' . $e->getMessage()];
            }
        };
    }
}
