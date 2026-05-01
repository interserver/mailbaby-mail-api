<?php
declare(strict_types=1);

namespace app\Mcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use support\Request as WebmanRequest;
use support\Response as WebmanResponse;

/**
 * Glue between Webman's Workerman-based Request/Response objects and PSR-7,
 * so we can hand a real PSR-7 ServerRequest to the MCP transport and emit
 * its PSR-7 response back through Webman.
 */
class Bridge
{
    public static function toPsr7(WebmanRequest $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri((string)$request->uri());
        $psr = $factory->createServerRequest($request->method(), $uri, $_SERVER ?: []);

        foreach ((array)$request->header() as $name => $value) {
            $psr = $psr->withHeader((string)$name, is_array($value) ? $value : [(string)$value]);
        }

        $rawBody = (string)$request->rawBody();
        $psr = $psr->withBody($factory->createStream($rawBody));

        $contentType = (string)$request->header('content-type', '');
        if (stripos($contentType, 'application/json') !== false && $rawBody !== '') {
            $parsed = json_decode($rawBody, true);
            if (is_array($parsed)) {
                $psr = $psr->withParsedBody($parsed);
            }
        } else {
            $post = $request->post();
            if (is_array($post) && !empty($post)) {
                $psr = $psr->withParsedBody($post);
            }
        }

        $get = $request->get();
        if (is_array($get) && !empty($get)) {
            $psr = $psr->withQueryParams($get);
        }

        return $psr;
    }

    public static function fromPsr7(ResponseInterface $response): WebmanResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string)$name] = is_array($values) ? implode(', ', $values) : (string)$values;
        }
        return new WebmanResponse(
            $response->getStatusCode(),
            $headers,
            (string)$response->getBody()
        );
    }
}
