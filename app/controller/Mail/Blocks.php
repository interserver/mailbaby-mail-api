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

class Blocks extends BaseController
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
        $data = $request->post('data');
        $type = $request->post('type');
        $orderId = $request->post('orderId', null);
        if (!v::intVal()->validate($orderId))
            return response('The specified ID was invalid.', 400);
        if (is_null($orderId)) {
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
            $username = $users[0];
        } else {
            $row = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->where('mail_id', $id)
                ->first();
            $username = $row->mail_username;
            if (count($users) == 0) {
                return $this->jsonErrorResponse('No active mail orders.', 400);
            }
        }
        if (!v::in(['domain', 'email', 'startswith'])->validate($type)) {
            return $this->jsonErrorResponse('Invalid value for type.', 400);
        }
        if ($type == 'domain' && !v::domain()->validate($data)) {
            return $this->jsonErrorResponse('Invalid domain name in data.', 400);
        }
        if ($type == 'email' && !v::email()->validate($data)) {
            return $this->jsonErrorResponse('Invalid email address in data.', 400);
        }
        if ($type == 'startswith' && !v::regex('/^[A-Z0-9+_\.-]+$/')->validate($data)) {
            return $this->jsonErrorResponse('Invalid email start string, it should contain only alphanumeric characters, +_.-', 400);
        }
        $rows = Db::connection('zonemta')
            ->table('mail_spam')
            ->insert([
                'user' => $username,
                'type' => $type,
                'data' => $data
            ]);
        return json(['status' =>'ok', 'text' => $transId]);
    }
}
