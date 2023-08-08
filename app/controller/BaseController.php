<?php
namespace app\controller;

use support\Request;
use support\Response;
use Respect\Validation\Validator as v;

class BaseController
{

	/**
	* returns a json response
	*
	* @param array $body array of data to pass
	* @param int $status status code
	* @return \support\Response
	*/
	public function jsonResponse($body, $status = 200) : Response {
		return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
	}

	/**
	* returns a json error response
	*
	* @param string $message the error details
	* @param int $status the error code
	* @return \support\Response
	*/
	public function jsonErrorResponse($message, $status = 200) : Response {
		return new Response($status, ['Content-Type' => 'application/json'], json_encode(['code' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE));
	}

}
