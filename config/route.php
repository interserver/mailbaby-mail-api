<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

Route::get('/', function($request) {
	$accept = (string)$request->header('accept', '');
	$base = ($request->header('x-forwarded-proto', null) ?: 'https') . '://' . ($request->header('host') ?: 'api.mailbaby.net');
	// Markdown content-negotiation for agent crawlers.
	if (stripos($accept, 'text/markdown') !== false || stripos($accept, 'text/x-markdown') !== false) {
		$llms = public_path() . '/llms.txt';
		if (is_file($llms)) {
			return response(file_get_contents($llms))
				->withHeader('Content-Type', 'text/markdown; charset=utf-8')
				->withHeader('Link', '<' . $base . '/spec/openapi.yaml>; rel="describedby"; type="application/yaml"');
		}
	}
	$file = public_path() . '/index.html';
	$body = is_file($file) ? file_get_contents($file) : '<a href="https://www.mail.baby/">Mail.Baby</a>';
	return response($body)
		->withHeader('Content-Type', 'text/html; charset=utf-8')
		->withHeader('Link', '<' . $base . '/spec/openapi.yaml>; rel="describedby"; type="application/yaml", '
			. '<' . $base . '/.well-known/api-catalog>; rel="api-catalog", '
			. '<' . $base . '/.well-known/mcp/server.json>; rel="mcp-server-card", '
			. '<' . $base . '/llms.txt>; rel="alternate"; type="text/markdown"');
});

Route::get('/ping', function($request) {
	return response('Server is up and running', 200);
});
Route::options('/ping', function($request) {
	return response('Server is up and running', 200);
});

// MCP server (Model Context Protocol — Streamable HTTP transport).
Route::any('/mcp', [app\controller\Mcp::class, 'handle']);
Route::any('/mcp/{path:.+}', [app\controller\Mcp::class, 'handle']);

// Discovery / agent-readiness endpoints.
Route::get('/.well-known/oauth-protected-resource', [app\controller\Mcp::class, 'oauthProtectedResource']);
Route::get('/.well-known/mcp/server.json', [app\controller\Mcp::class, 'serverCard']);
Route::get('/.well-known/api-catalog', function ($request) {
	$base = ($request->header('x-forwarded-proto', null) ?: 'https') . '://' . ($request->header('host') ?: 'api.mailbaby.net');
	return json([
		'linkset' => [[
			'anchor' => $base . '/',
			'service-desc' => [
				['href' => $base . '/spec/openapi.yaml', 'type' => 'application/yaml'],
				['href' => $base . '/spec/openapi.json', 'type' => 'application/json'],
			],
			'service-doc' => [
				['href' => $base . '/swagger-ui.html', 'type' => 'text/html'],
				['href' => $base . '/redoc.html',     'type' => 'text/html'],
			],
		]],
	]);
});

Route::group('/mail', function() {
	Route::any('', [app\controller\Mail::class, 'index']);
    Route::post('/send', [app\controller\Mail::class, 'send']);
    Route::post('/rawsend', [app\controller\Mail::class, 'rawsend']);
    Route::post('/advsend', [app\controller\Mail::class, 'advsend']);
    Route::any('/log', [app\controller\Mail::class, 'log']);
    Route::delete('/rules/{id}', [app\controller\Mail\Rules::class, 'delete']);
    Route::get('/rules', [app\controller\Mail\Rules::class, 'get']);
    Route::post('/rules', [app\controller\Mail\Rules::class, 'post']);
    Route::get('/stats', [app\controller\Mail\Stats::class, 'get']);
    Route::post('/blocks/delete', [app\controller\Mail\Blocks::class, 'delete']);
    Route::get('/blocks', [app\controller\Mail\Blocks::class, 'get']);
    Route::get('/{id}', [app\controller\Mail::class, 'view']);
})->middleware([
	app\middleware\AuthCheck::class
]);
// Set cross domain for all OPTIONS requests
Route::options('[{path:.+}]', function (){
    return response('');
});
Route::disableDefaultRoute();
