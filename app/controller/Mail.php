<?php
namespace app\controller;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Respect\Validation\Validator as v;

class Mail
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
		return new Response($status, ['Content-Type' => 'application/json'], ['code' => $status, 'message' => $message]);
	}

	public function index(Request $request) : Response {
		$accountInfo = $request->accountInfo;
		$orders = Db::table('mail')
			->where('mail_custid', $accountInfo->account_id)
			->get();
		$return = [];
		foreach ($orders as $order) {
			$row = [
				'id' => $order->mail_id,
				'status' => $order->mail_status,
				'username' => $order->mail_username,
			];
			if ($order->mail_comment != '')
				$row['comment'] = $order->mail_comment;
			$return[] = $row;
		}
		return json($return);
	}

	public function view(Request $request, $id) : Response {
		$accountInfo = $request->accountInfo;
		if (!v::intVal()->validate($id))
			return response('The specified ID was invalid.', 400);
		$order = Db::table('mail')
			->where('mail_custid', $accountInfo->account_id)
			->where('mail_id', $id)
			->first();
		$return = [
			'id' => $order->mail_id,
			'status' => $order->mail_status,
			'username' => $order->mail_username,
			'password' => $this->getMailPassword($request, $id),
		];
		if ($order->mail_comment != '')
			$row['comment'] = $order->mail_comment;
		return json($return);
	}

    public function send(Request $request) : Response {
    	if ($request->method() != 'POST')
    		return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'This should be a POST request.'], JSON_UNESCAPED_UNICODE));
        $accountInfo = $request->accountInfo;
        $id = $request->post('id');
        if (!is_null($id)) {
            if (!v::intVal()->validate($id))
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified ID was invalid.'], JSON_UNESCAPED_UNICODE));
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_id', $id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'The mail order with the specified ID was not found or not active.'], JSON_UNESCAPED_UNICODE));
        } else {
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'No active mail order was found.'], JSON_UNESCAPED_UNICODE));
            $id = $order->mail_id;
        }
        $sent = false;
        $from = $request->post('from');
        $email = $request->post('body');
        $subject = $request->post('subject');
        $isHtml = strip_tags($email) != $email;
        $who = $request->post('to');
        if (!is_array($who))
            $who = [$who];
        $username = (string)$order->mail_username;
        $password = (string)$this->getMailPassword($request, $id);
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'utf-8';
        $mailer->isSMTP();
        $mailer->Port = 25;
        $mailer->Host = 'relay.mailbaby.net';
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Subject = $subject;
        $mailer->isHTML($isHtml);
        try {
            $mailer->setFrom($from);
            $mailer->addReplyTo($from);
            foreach ($who as $to)
                $mailer->addAddress($to);
            $mailer->Body = $email;
            $mailer->preSend();
            if (!$mailer->send()) {
                return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
            }
            return json(['status' =>'ok', 'text' => 'ok']);
        } catch (Exception $e) {
            return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
        }
    }


    public function advsend(Request $request) : Response {
    	if ($request->method() != 'POST')
    		return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'This should be a POST request.'], JSON_UNESCAPED_UNICODE));
        $accountInfo = $request->accountInfo;
        if ($request->header('content-type') == 'application/x-www-form-urlencoded') {
        	$data = [];
            foreach (['subject', 'body', 'from', 'to', 'id', 'replyto', 'cc', 'bcc'] as $var) {
				$value = $request->post($var);
				if (!is_null($value)) {
					$data[$var] = $value;
				}
            }
		} else {
			$data = json_decode($request->rawBody(), true);
        }
        $id = isset($data['id']) ? $data['id'] : null;
        if (!is_null($id)) {
            if (!v::intVal()->validate($id))
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified ID was invalid.'], JSON_UNESCAPED_UNICODE));
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_id', $id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'The mail order with the specified ID was not found or not active.'], JSON_UNESCAPED_UNICODE));
        } else {
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'No active mail order was found.'], JSON_UNESCAPED_UNICODE));
            $id = $order->mail_id;
        }
        foreach (['from', 'to', 'subject', 'body'] as $field)
            if (!isset($data[$field]))
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'Missing the required "'.$field.'" field'], JSON_UNESCAPED_UNICODE));


        $sent = false;
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'utf-8';
        $mailer->isSMTP();
        $mailer->Port = 25;
        $mailer->Host = 'relay.mailbaby.net';
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$order->mail_username;
        $mailer->Password = (string)$this->getMailPassword($request, $id);
        $mailer->Subject = $data['subject'];
        $mailer->isHTML(strip_tags($data['body']) != $data['body']);
        try {
            $mailer->setFrom($data['from']['email'], isset($data['from']['name']) ? $data['from']['name'] : '');
            foreach ($data['to'] as $contact)
                $mailer->addAddress($contact['email'], isset($contact['name']) ? $contact['name'] : '');
            foreach (['ReplyTo', 'CC', 'BCC'] as $type) {
                if (isset($data[strtolower($type)])) {
                    if (is_array($data[strtolower($type)])) {
                        if (count($data[strtolower($type)]) > 0) {
                            foreach ($data[strtolower($type)] as $contact) {
                                $call = 'add'.$type;
                                $mailer->$call($contact['email'], isset($contact['name']) ? $contact['name'] : '');
                            }
                        }
                    } else {
                        return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'The "'.strtolower($type).'" field is supposed to be an array.'], JSON_UNESCAPED_UNICODE));
                    }
                }
            }
            if (isset($data['attachments'])) {
                if (is_array($data['attachments'])) {
                    if (count($data['attachments']) > 0) {
                        foreach ($data['attachments'] as $idx => $attachment) {
                            $fileData = base64_decode($attachment['data']);
                            $localFile = tempnam(sys_get_temp_dir(), 'attachment');
                            file_put_contents($localFile, $fileData);
                            $mailer->addAttachment($localFile, isset($attachment['filename']) ? $attachment['filename'] : '');
                        }
                    }
                } else {
                    return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'The "attachments" field is supposed to be an array.'], JSON_UNESCAPED_UNICODE));
                }
            }
            $mailer->Body = $data['body'];
            $mailer->preSend();
            if (!$mailer->send()) {
                return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
            }
            return json(['status' =>'ok', 'text' => 'ok']);
        } catch (Exception $e) {
            return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
        }
    }

	public function log(Request $request) {
		$accountInfo = $request->accountInfo;
		$id = $request->get('id', null);
		$limit = $request->get('limit', 100);
		$skip = $request->get('skip', 0);
        $search = $request->get('search', '');
        $startDate = $request->get('startDate', null);
        $endDate = $request->get('endDate', null);
        $origin = $request->get('origin', null);
        $mx = $request->get('mx', null);
        $from = $request->get('from', null);
        $to = $request->get('to', null);
        $subject = $request->get('subject', null);
        $mailId = $request->get('mailid', null);
        if (!v::anyOf(v::intVal(), v::nullType())->validate($startDate))
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified startDate value '.var_export($startDate).' was invalid.'], JSON_UNESCAPED_UNICODE));
        if (!v::anyOf(v::intVal(), v::nullType())->validate($endDate))
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified endDate value '.var_export($endDate).' was invalid.'], JSON_UNESCAPED_UNICODE));
        if (!v::intVal()->validate($skip))
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified skip value was invalid.'], JSON_UNESCAPED_UNICODE));
        if (!v::intVal()->validate($limit))
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified limit value was invalid.'], JSON_UNESCAPED_UNICODE));
		if (!is_null($id)) {
			if (!v::intVal()->validate($id))
				return new Response(400, ['Content-Type' => 'application/json'], json_encode(['code' => 400, 'message' => 'The specified ID was invalid.'], JSON_UNESCAPED_UNICODE));
			$order = Db::table('mail')
				->where('mail_custid', $accountInfo->account_id)
				->where('mail_id', $id)
				->where('mail_status', 'active')
				->first();
			if (is_null($order))
				return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'The mail order with the specified ID was not found or not active.'], JSON_UNESCAPED_UNICODE));
		} else {
			$order = Db::table('mail')
				->where('mail_custid', $accountInfo->account_id)
				->where('mail_status', 'active')
				->first();
			if (is_null($order))
				return new Response(404, ['Content-Type' => 'application/json'], json_encode(['code' => 404, 'message' => 'No active mail order was found.'], JSON_UNESCAPED_UNICODE));
		}
		$id = $order->mail_id;
        $where = [];
        $where[] = ['mail_messagestore.user', '=', 'mb'.$id];
        if (!is_null($startDate))
            $where[] = ['mail_messagestore.time', '>=', (int)$startDate];
        if (!is_null($endDate))
            $where[] = ['mail_messagestore.time', '<=', (int)$endDate];
        //error_log('Mail Where:'.json_encode($where));
   		$total = Db::connection('zonemta')
   			->table('mail_messagestore')
			->where($where)
			->count();
        //error_log('Mail Total:'.$total);
		$return = [
			'total' => $total,
			'skip' => $skip,
			'limit' => $limit,
			'emails' => []
		];
   		$orders = Db::connection('zonemta')
   			->table('mail_messagestore')
   			->leftJoin('mail_messageheaders', 'mail_messagestore.id', '=', 'mail_messageheaders.id')
   			->leftJoin('mail_senderdelivered', 'mail_messagestore.id', '=', 'mail_senderdelivered.id')
   			->leftJoin('mail_senderdelivered_extra', 'mail_senderdelivered._id', '=', 'mail_senderdelivered_extra.senderdelivered_id')
               ->select('mail_messagestore._id', 'mail_messagestore.id', 'mail_messagestore.from', 'mail_messagestore.to', 'mail_messageheaders.subject', 'mail_messageheaders.messageId', 'mail_messageheaders.created', 'mail_messageheaders.time', 'mail_messageheaders.user', 'mail_messageheaders.transtype', 'mail_messageheaders.transhost', 'mail_messageheaders.originhost', 'mail_messageheaders.origin', 'mail_messageheaders.interface', 'mail_messageheaders.date', 'mail_senderdelivered.sendingZone', 'mail_senderdelivered.bodySize', 'mail_senderdelivered.sourceMd5', 'mail_senderdelivered.seq', 'mail_senderdelivered.domain', 'mail_senderdelivered.recipient', 'mail_senderdelivered.locked', 'mail_senderdelivered.lockTime', 'mail_senderdelivered.assigned', 'mail_senderdelivered.queued', 'mail_senderdelivered._lock', 'mail_senderdelivered.logger', 'mail_senderdelivered.mxPort', 'mail_senderdelivered.connectionKey', 'mail_senderdelivered.mxHostname', 'mail_senderdelivered.sentBodyHash', 'mail_senderdelivered.sentBodySize', 'mail_senderdelivered.md5Match', 'mail_senderdelivered.fbl', 'mail_senderdelivered_extra.doc')
            ->where($where)
			->offset($skip)
			->limit($limit)
			->get();
		$return['emails'] = $orders->all();
		return json($return);
	}

	public function viewtest(Request $request)
	{
		return view('index/view', ['name' => 'webman']);
	}

	public function json(Request $request)
	{
		return json(['code' => 0, 'msg' => 'ok']);
	}

	public function file(Request $request)
	{
		$file = $request->file('upload');
		if ($file && $file->isValid()) {
			$file->move(public_path().'/files/myfile.'.$file->getUploadExtension());
			return json(['code' => 0, 'msg' => 'upload success']);
		}
		return json(['code' => 1, 'msg' => 'file not found']);
	}

	/**
	* returns the current password for a mail account
	*
	* @param Request $request
	* @param int $id
	* @return null|string the current password or null on no matching password
	*/
	private function getMailPassword(Request $request, $id) {
		$password = Db::table('history_log')
			->where('history_type', 'password')
			->where('history_section', 'mail')
			->where('history_creator', $request->accountInfo->account_id)
			->where('history_new_value', $id)
			->orderBy('history_timestamp', 'desc')
			->first('history_old_value');
		return $password->history_old_value;
	}
}
