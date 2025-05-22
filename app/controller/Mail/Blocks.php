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
        $lines = `echo "select Date,SMTPFrom,MessageId,Subject,MimeRecipients from rspamd where arrayJoin(Symbols.Names)='LOCAL_BL_RCPT' and (TS > (NOW() - toIntervalHour({$hoursHistory}))) and AuthUser IN ('{$userStr}') order by Date desc" | curl -s 'http://clickhouse.mailbaby.net:8123/?query=' --data-binary  @-`;
        if (!is_null($lines) && $lines != '') {
            $lines = explode("\n", trim($lines));
            foreach ($lines as $line) {
                $line = explode("\t", $line);
                $return['local'][]  = [
                    'date' => $line[0],
                    'from' => $line[1],
                    'messageId' => $line[2] == 'undef' ? null : $line[2],
                    'subject' => $line[3],
                    'to' => $line[4],
                ];
            }
        }
        $lines = `echo "select Date,SMTPFrom,MessageId,Subject,MimeRecipients from rspamd where arrayJoin(Symbols.Names)='MBTRAP' and (TS > (NOW() - toIntervalHour({$hoursHistory}))) and AuthUser IN ('{$userStr}') order by Date desc" | curl -s 'http://clickhouse.mailbaby.net:8123/?query=' --data-binary  @-`;
        if (!is_null($lines) && $lines != '') {
            $lines = explode("\n", trim($lines));
            foreach ($lines as $line) {
                $line = explode("\t", $line);
                $return['mbtrap'][]  = [
                    'date' => $line[0],
                    'from' => $line[1],
                    'messageId' => $line[2] == 'undef' ? null : $line[2],
                    'subject' => $line[3],
                    'to' => $line[4],
                ];
            }
        }
        $rows = Db::connection('rspamd')
            ->table('rspamd')
            ->select('fromemail as from', 'headersubject as subject')
            ->whereIn('user', $users)
            ->whereRaw('date > NOW() - INTERVAL '.$subjectBlockHours.' HOUR')
            ->where(function($query) {
                $query->where('headersubject', 'like', '%@%')
                    ->orWhere('headersubject', 'like', '%smtp%')
                    ->orWhere('headersubject', 'like', '%socks5%')
                    ->orWhere('headersubject', 'like', '%socks4%');
            })
            ->groupBy('fromemail', 'headersubject')
            ->havingRaw('COUNT(headersubject) > 4')
            ->get();
        $return['subject'] = $rows->all();
        return $this->jsonResponse($return);
    }

    public function delete(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $email = $request->post('email');
        Db::connection('rspamd')
            ->table('rspamd')
            ->where('fromemail', $email)
            ->delete();
        Db::connection('rspamd')
            ->table('mailchannels')
            ->where('email', $email)
            ->delete();
        Db::connection('rspamd')
            ->table('mailbaby')
            ->where('emailfrom', $email)
            ->delete();
        return $this->jsonResponse(['status' =>'ok', 'record deleted']);
    }
}
