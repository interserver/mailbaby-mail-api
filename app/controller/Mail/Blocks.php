<?php
namespace app\controller\Mail;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use app\controller\BaseController;
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
        $output = '';
        $daysHistory = 5;
        $hoursHistory = $daysHistory * 24;
        $manualBlockDays = 30;
        $subjectBlockDays = 3;
        $subjectBlockHours = $subjectBlockDays * 24;
        $return = [
            'local' => [],
            'mbtrap' => [],
            'subject' => [],
        ];
        $userStr = implode("','", $users);
        $lines = trim(`echo "select Date,SMTPFrom,MessageId,Subject,MimeRecipients from rspamd where arrayJoin(Symbols.Names)='LOCAL_BL_RCPT' and (TS > (NOW() - toIntervalHour({$hoursHistory}))) and AuthUser IN ('{$userStr}') order by Date desc" | curl -s 'http://clickhouse.mailbaby.net:8123/?query=' --data-binary  @-`);
        if ($lines != '') {
            $lines = explode("\n", $lines);
            foreach ($lines as $line) {
                $line = explode("\t", $line);
                $return['local'][]  = [
                    'Date' => $line[0],
                    'SMTPFrom' => $line[1],
                    'MessageId' => $line[2],
                    'Subject' => $line[3],
                    'MimeRecipients' => $line[4],
                ];
            }
        }
        $lines = trim(`echo "select Date,SMTPFrom,MessageId,Subject,MimeRecipients from rspamd where arrayJoin(Symbols.Names)='MBTRAP' and (TS > (NOW() - toIntervalHour({$hoursHistory}))) and AuthUser IN ('{$userStr}') order by Date desc" | curl -s 'http://clickhouse.mailbaby.net:8123/?query=' --data-binary  @-`);
        if ($lines != '') {
            $lines = explode("\n", $lines);
            foreach ($lines as $line) {
                $line = explode("\t", $line);
                $return['mbtrap'][]  = [
                    'Date' => $line[0],
                    'SMTPFrom' => $line[1],
                    'MessageId' => $line[2],
                    'Subject' => $line[3],
                    'MimeRecipients' => $line[4],
                ];
            }
        }
        /*
        $db = new Db(ZONEMTA_RSPAMD_MYSQL_DB, ZONEMTA_RSPAMD_MYSQL_USERNAME, ZONEMTA_RSPAMD_MYSQL_PASSWORD, ZONEMTA_RSPAMD_MYSQL_HOST);
        $db->query("SELECT fromemail, headersubject FROM rspamd WHERE user = '{$username}' AND date > NOW() - INTERVAL {$subjectBlockHours} HOUR and (headersubject LIKE '%@%' OR headersubject LIKE '%smtp%' OR headersubject LIKE '%socks5%' OR headersubject LIKE '%socks4%') GROUP BY headersubject HAVING COUNT(headersubject) > 4", __LINE__, __FILE__);
        while ($db->next_record(MYSQL_ASSOC)) {
            $return['subject'][] = $db->Record;
        }
        */
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
        /*
        $db = new Db(ZONEMTA_RSPAMD_MYSQL_DB, ZONEMTA_RSPAMD_MYSQL_USERNAME, ZONEMTA_RSPAMD_MYSQL_PASSWORD, ZONEMTA_RSPAMD_MYSQL_HOST);
        $username = $db->real_escape($this->serviceInfo['mail_username']);
        if (isset($GLOBALS['tf']->variables->request['unblock'])) {
            $unblock = $db->real_escape(trim($GLOBALS['tf']->variables->request['unblock']));
            $db->query("delete from rspamd where fromemail='{$unblock}'", __LINE__, __FILE__);
            $db->query("delete from mailchannels where fromemail='{$unblock}'", __LINE__, __FILE__);
            $db->query("delete from mailbaby where fromemail='{$unblock}'", __LINE__, __FILE__);
            add_output("Email '$unblock' Unblocked/Delisted<br>");
        }
        */
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
