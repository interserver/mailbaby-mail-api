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
}
