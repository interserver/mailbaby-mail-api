<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AccessControl implements MiddlewareInterface
{
	public function process(Request $request, callable $next) : Response
	{
		$response = $request->method() == 'OPTIONS' ? response('') : $next($request);
		$response->withHeaders([
			'Access-Control-Allow-Origin' => '*',
			'Access-Control-Allow-Methods' => '*',
			'Access-Control-Allow-Headers' => '*'
		]);

		return $response;
	}
}