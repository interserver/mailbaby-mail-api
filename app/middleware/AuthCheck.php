<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Db;
//use support\bootstrap\Log;

class AuthCheck implements MiddlewareInterface
{
    /**
    * process authentication
    *
    * @param \support\Request $request
    * @param callable $next
    * @return \support\Response
    */
	public function process(Request $request, callable $next) : Response {
		$key = $request->header('x-api-key');
		if (is_null($key) || $key === '') {
			return new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'message' => 'API key is missing or invalid'], JSON_UNESCAPED_UNICODE));
		}
		$accountInfo = Db::table('accounts')
			->leftJoin('account_security', 'account_security.account_id', '=', 'accounts.account_id')
			->where('account_sec_type', 'api_key')
			->where('account_sec_data', $key)
			->first();
		if (is_null($accountInfo)) {
			return new Response(401, ['Content-Type' => 'application/json'], json_encode(['code' => 401, 'message' => 'API key is missing or invalid'], JSON_UNESCAPED_UNICODE));
		}
		$request->accountInfo = $accountInfo;
		return $next($request);
	}
}
