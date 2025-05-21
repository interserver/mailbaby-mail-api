<?php
namespace app\controller\Mail;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use app\controller\BaseController;
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
        /**
        * @var \Illuminate\Support\Collection<int, \stdClass>
        */
        $rows = Db::connection('zonemta')
            ->table('mail_spam')
            ->whereIn('user', $users)
            ->get();
        $return = $rows->all();
        return $this->jsonResponse($return);

/*
        $module = 'mail';
        $settings = get_module_settings($module);
        if ($id === false) {
            $id = $_GET['id'];
        }
        $id = intval($id);
        $fieldLabels = ['origin' => 'IP', 'mail_messagestore.to' => 'To', 'mail_messagestore.from' => 'From'];
        $fieldKeys = ['origin' => 'ip', 'mail_messagestore.to' => 'to', 'mail_messagestore.from' => 'from'];
        $times = ['all' => 'All Time', 'billing' => 'Billing Cycle', 'month' => 'This Month', '7d' => '7 Days', '24h' => '24 Hours', 'day' => 'Today', '1h' => '1 Hour'];
        $types = [
            'volumeip' => 'origin',
            'volumeto' => 'mail_messagestore.to',
            'volumefrom' => 'mail_messagestore.from',
        ];
        $users = [];
        $where = [];
        $serviceInfo = get_service($id, $module);
        header('Content-type: application/json; charset=UTF-8');
        if ($serviceInfo === false) {
            return json_response(['status' => 'error', 'text' => 'Invalid or missing mail order id']);
        }
        if (!isset($_GET['type'])) {
            return json_response(['status' => 'error', 'text' => 'missing the query type']);
        }
        $limit = 500;
        $users[] = $serviceInfo['mail_username'];
        $currency = $serviceInfo[$settings['PREFIX'].'_currency'];
        $currencySymbol = Currency::getSymbol($currency);
        $repeatInvoice = get_service_repeat_invoice($serviceInfo['mail_invoice'], $module);
        $db = new Db(ZONEMTA_MYSQL_DB, ZONEMTA_MYSQL_USERNAME, ZONEMTA_MYSQL_PASSWORD, ZONEMTA_MYSQL_HOST);
        if (count($users) > 0) {
            $where[] = count($users) > 1 ? "user in ('".implode("','", $users)."')" : "user='{$users[0]}'";
        }
        $emailCost = 0.20 / 1000;
        $baseCost = 1 * ($repeatInvoice === false ? 1 : $repeatInvoice['repeat_invoices_frequency']);
        $baseCost = convertCurrency($baseCost, $currency, 'USD');
        $startDate = $db->fromTimestamp(date('Y-m-d 00:00:01', $db->fromTimestamp($repeatInvoice === false ? $serviceInfo['mail_order_date'] : $repeatInvoice['repeat_invoices_last_date'])));
        $endDate = $db->fromTimestamp(date('Y-m-d 00:00:01', $db->fromTimestamp($repeatInvoice === false ? date('Y-m-d H:i:s') : $repeatInvoice['repeat_invoices_next_date'])));
        $totals = [
            'usage' => 0,
            'currency' => $currency,
            'currencySymbol' => $currencySymbol,
            'cost' => 0,
            'received' => 0,
            'sent' => 0,
        ];

        $twhere = $where;
        $twhere[] = "time between '{$startDate}' and '{$endDate}'";
        $db->query("select count(id) as fieldcount from mail_messagestore ".(count($where) > 0 ? "where ".implode(" and ", $twhere) : ""), __LINE__, __FILE__);
        $db->next_record(MYSQL_ASSOC);
        $totals['usage'] = (int)$db->Record['fieldcount'];
        $countCost = ceil($totals['usage'] * $emailCost * 100) / 100;
        $countCost = convertCurrency($countCost, $currency, 'USD');
        $totalCost = $baseCost->plus($countCost);
        $totals['cost'] = $totalCost->getAmount()->toFloat();

        $time = isset($GLOBALS['tf']->variables->request['time']) && in_array($GLOBALS['tf']->variables->request['time'], array_keys($times)) ? $GLOBALS['tf']->variables->request['time'] : '1h';
        if ($time == 'month') {
            $where[] = 'time >= '.mktime(0, 0, 0, date('n'), 1, date('Y'));
        } elseif ($time == 'billing') {
            $where[] = 'time >= '.$startDate;
        } elseif ($time == '7d') {
            $where[] = 'time >= '.(time() - (86400 * 7));
        } elseif ($time == '24h') {
            $where[] = 'time >= '.(time() - 86400);
        } elseif ($time == '1d') {
            $where[] = 'time >= '.(time() - 86400);
        } elseif ($time == '1h') {
            $where[] = 'time >= '.(time() - 3600);
        } elseif ($time == 'day') {
            $where[] = 'time >= '.mktime(0, 0, 0, date('n'), date('j'), date('Y'));
        }
        $values = [];
        $db->query("select count(id) as fieldcount from mail_messagestore ".(count($where) > 0 ? "where ".implode(" and ", $where) : ""), __LINE__, __FILE__);
        $db->next_record(MYSQL_ASSOC);
        $totals['received'] = (int)$db->Record['fieldcount'];

        $db->query("select count(id) as fieldcount from mail_messagestore left join mail_senderdelivered using (id) ".(count($where) > 0 ? "where ".implode(" and ", $where) : "")." and mail_senderdelivered.id is not null", __LINE__, __FILE__);
        $db->next_record(MYSQL_ASSOC);
        $totals['sent'] = (int)$db->Record['fieldcount'];

        $field = $types[$_GET['type']];
        $values[$field] = [];
        $db->query("select {$field} as field,count({$field}) as fieldcount from mail_messagestore ".(count($where) > 0 ? "where ".implode(" and ", $where) : "")." group by {$field} order by count({$field}) desc limit {$limit}", __LINE__, __FILE__);
        while ($db->next_record(MYSQL_ASSOC)) {
            $db->Record['fieldcount'] = (int)$db->Record['fieldcount'];
            $values[$field][] = $db->Record;
        }
        echo json_encode($values);

        return json_response($totals);
*/


    }
}
