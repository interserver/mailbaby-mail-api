<?php
namespace app\controller\Mail;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use app\controller\BaseController;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Respect\Validation\Validator as v;

class Stats extends BaseController
{
    public function get(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $rows = Db::table('mail')
            ->where('mail_custid', $accountInfo->account_id)
            ->where('mail_status', 'active')
            ->get();
        $users = [];
        foreach ($rows as $row) {
            $users[] = $row->mail_username;
        }
        if (count($users) == 0) {
            return $this->jsonErrorResponse('No active mail orders.', 400);
        }
        $rows = Db::connection('zonemta')
            ->table('mail_spam')
            ->whereIn('user', $users)
            ->get();
        $return = $rows->all();
        return json($return);
    }

    public function delete(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $id = $request->post('id');
        if (!v::intVal()->validate($id))
            return response('The specified ID was invalid.', 400);
        $rows = Db::table('mail')
            ->where('mail_custid', $accountInfo->account_id)
            ->where('mail_status', 'active')
            ->get();
        $users = [];
        foreach ($rows as $row) {
            $users[] = $row->mail_username;
        }
        if (count($users) == 0) {
            return $this->jsonErrorResponse('No active mail orders.', 400);
        }
        $rows = Db::connection('zonemta')
            ->table('mail_spam')
            ->whereIn('user', $users)
            ->where('id', $id)
            ->delete();
        return json(['status' =>'ok', 'record deleted']);
    }

    public function post(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $rows = Db::table('mail')
            ->where('mail_custid', $accountInfo->account_id)
            ->where('mail_status', 'active')
            ->get();
        $users = [];
        foreach ($rows as $row) {
            $users[] = $row->mail_username;
        }
        if (count($users) == 0) {
            return $this->jsonErrorResponse('No active mail orders.', 400);
        }
        return json(['status' =>'ok', 'text' => $transId]);
    }


    public function advsend(Request $request) : Response {
        if ($request->method() != 'POST')
            return $this->jsonErrorResponse('This should be a POST request.', 400);
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
                return $this->jsonErrorResponse('The specified ID was invalid.', 400);
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_id', $id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('The mail order with the specified ID was not found or not active.', 404);
        } else {
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('No active mail order was found.', 404);
            $id = $order->mail_id;
        }
        foreach (['from', 'to', 'subject', 'body'] as $field)
            if (!isset($data[$field]))
            return $this->jsonErrorResponse('Missing the required "'.$field.'" field', 404);


            $sent = false;
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'utf-8';
        $mailer->isSMTP();
        $mailer->Port = 25;
        $mailer->Host = 'relay.mailbaby.net';
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$order->mail_username;
        $mailer->Password = (string)$this->getMailPassword($request, $id);
        //Enable SMTP debugging
        //SMTP::DEBUG_OFF = off (for production use)
        //SMTP::DEBUG_CLIENT = client messages
        //SMTP::DEBUG_SERVER = client and server messages
        $mailer->SMTPDebug = SMTP::DEBUG_OFF;
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
                        return $this->jsonErrorResponse('The "'.strtolower($type).'" field is supposed to be an array.', 404);
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
                    return $this->jsonErrorResponse('The "attachments" field is supposed to be an array.', 404);
                }
            }
            $mailer->Body = $data['body'];
            $mailer->preSend();
            if (!$mailer->send()) {
                return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
            }
            // SERVER -> CLIENT: 250 Message queued as 185caa69ff7000f47c
            $transId = $mailer->getSMTPInstance()->getLastTransactionID();
            return json(['status' =>'ok', 'text' => $transId]);
        } catch (Exception $e) {
            return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
        }
    }
}
