<?php
namespace app\controller\Mail;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use app\controller\BaseController;
use Respect\Validation\Validator as v;

class Rules extends BaseController
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
        return $this->jsonResponse($return);
    }

    public function delete(Request $request, $id) : Response {
        $accountInfo = $request->accountInfo;
        //$id = $request->post('id');
        if (!v::intVal()->validate($id))
            return $this->jsonErrorResponse('The specified ID was invalid.', 400);
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
        return $this->jsonResponse(['status' =>'ok', 'text' => 'record deleted']);
    }

    public function post(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $data = $request->post('data');
        $type = $request->post('type');
        $username = $request->post('username', null);
        if (is_null($username)) {
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
                ->where('mail_username', $username)
                ->first();
            $username = $row->mail_username;
            if (count($users) == 0) {
                return $this->jsonErrorResponse('Invalid or Inactive Username.', 400);
            }
        }
        if (!v::in(['domain', 'email', 'startswith', 'destination'])->validate($type)) {
            return $this->jsonErrorResponse('Invalid value for type.', 400);
        } elseif ($type == 'domain' && !v::domain()->validate($data)) {
            return $this->jsonErrorResponse('Invalid domain name in data.', 400);
        } elseif ($type == 'email' && !v::email()->validate($data)) {
            return $this->jsonErrorResponse('Invalid email address in data.', 400);
        } elseif ($type == 'destination' && !v::email()->validate($data)) {
            return $this->jsonErrorResponse('Invalid email address in data.', 400);
        } elseif ($type == 'startswith' && !v::regex('/^[A-Za-z0-9+_\.-]+$/')->validate($data)) {
            return $this->jsonErrorResponse('Invalid email start string, it should contain only alphanumeric characters, +_.-', 400);
        }
        $transId = Db::connection('zonemta')
            ->table('mail_spam')
            ->insertGetId([
                'user' => $username,
                'type' => $type,
                'data' => $data
            ]);
        return $this->jsonResponse(['status' =>'ok', 'text' => $transId]);
    }
}
