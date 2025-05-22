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
    public function fromTimestamp($timestamp)
    {
        if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts)) {
            $time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
        } elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts)) {
            $time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
        } elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts)) {
            $time = mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
        } elseif (is_numeric($timestamp) && $timestamp >= 943938000) {
            $time = $timestamp;
        } else {
            $time = false;
        }
        return $time;
    }

    public function get(Request $request) : Response {
        $accountInfo = $request->accountInfo;
        $rows = Db::table('mail')
            ->where('mail_custid', $accountInfo->account_id)
            ->where('mail_status', 'active')
            ->get();
        $users = [];
        foreach ($rows as $row) {
            $currency = $row->mail_currency;
            $users[] = $row->mail_username;
            $repeatId = $row->mail_invoice;
            $orderDate = $row->mail_order_date;
        }
        if (count($users) == 0) {
            return $this->jsonErrorResponse('No active mail orders.', 400);
        }
        $repeatInvoice = Db::table('repeat_invoices')
            ->where('repeat_invoices_id', '=', $repeatId)
            ->first();

        $fieldLabels = ['origin' => 'IP', 'mail_messagestore.to' => 'To', 'mail_messagestore.from' => 'From'];
        $fieldKeys = ['origin' => 'ip', 'mail_messagestore.to' => 'to', 'mail_messagestore.from' => 'from'];
        $times = ['all' => 'All Time', 'billing' => 'Billing Cycle', 'month' => 'This Month', '7d' => '7 Days', '24h' => '24 Hours', 'day' => 'Today', '1h' => '1 Hour'];
        $types = [
            'ip' => 'origin',
            'to' => 'mail_messagestore.to',
            'from' => 'mail_messagestore.from',
        ];
        $limit = 500;
        //$currencySymbol = Currency::getSymbol($currency);
        $emailCost = 0.20 / 1000;
        $baseCost = 1 * ($repeatInvoice === false ? 1 : $repeatInvoice->repeat_invoices_frequency);
        //$baseCost = convertCurrency($baseCost, $currency, 'USD');
        $startDate = $this->fromTimestamp(date('Y-m-d 00:00:01', $this->fromTimestamp($repeatInvoice === false ? $orderDate : $repeatInvoice->repeat_invoices_last_date)));
        $endDate = $this->fromTimestamp(date('Y-m-d 00:00:01', $this->fromTimestamp($repeatInvoice === false ? date('Y-m-d H:i:s') : $repeatInvoice->repeat_invoices_next_date)));
        $totals = [
            'time' => '1h',
            'usage' => 0,
            'currency' => $currency,
            //'currencySymbol' => $currencySymbol,
            'cost' => 0,
            'received' => 0,
            'sent' => 0,
            'volume' => [
                'to' => [],
                'from' => [],
                'ip' => [],
            ]
        ];
        $totals['usage'] = Db::connection('zonemta')
            ->table('mail_messagestore')
            ->whereIn('user', $users)
            ->whereBetween('time', [$startDate, $endDate])
            ->count();

        $countCost = ceil($totals['usage'] * $emailCost * 100) / 100;
        //$countCost = convertCurrency($countCost, $currency, 'USD');
        //$totalCost = $baseCost->plus($countCost);
        //$totals['cost'] = $totalCost->getAmount()->toFloat();
        $totals['cost'] = $baseCost + $countCost;

        $time = $request->get('time', '1h');

        $totals['time'] = $time;
        if ($time == 'month') {
            $minTime = mktime(0, 0, 0, date('n'), 1, date('Y'));
        } elseif ($time == 'billing') {
            $minTime = $startDate;
        } elseif ($time == '7d') {
            $minTime = (time() - (86400 * 7));
        } elseif ($time == '24h') {
            $minTime = (time() - 86400);
        } elseif ($time == '1d') {
            $minTime = (time() - 86400);
        } elseif ($time == '1h') {
            $minTime = (time() - 3600);
        } elseif ($time == 'day') {
            $minTime = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
        }
        $where[] = 'time >= '.$minTime;

        $totals['received'] = Db::connection('zonemta')
            ->table('mail_messagestore')
            ->whereIn('user', $users)
            ->where('time', '>=', $minTime)
            ->count();

        $totals['sent'] = Db::connection('zonemta')
            ->table('mail_messagestore')
            ->leftJoin('mail_senderdelivered', 'mail_messagestore.id', '=', 'mail_senderdelivered.id')
            ->whereIn('user', $users)
            ->where('time', '>=', $minTime)
            ->whereNotNull('mail_senderdelivered.id')
            ->count();

        foreach ($types as $idx => $field) {
            $values = [];
            /**
            * @var \Illuminate\Support\Collection<int, \stdClass>
            */
            $rows = Db::connection('zonemta')
                ->table('mail_messagestore')
                ->selectRaw("{$field} as field, count({$field}) as fieldcount")
                ->whereIn('user', $users)
                ->where('time', '>=', $minTime)
                ->groupBy($field)
                ->orderByRaw("count({$field}) desc")
                ->limit($limit)
                ->get();
            foreach ($rows as $row) {
                $values[$row->field] = $row->fieldcount;
            }
            $totals['volume'][$idx] = $values;
        }
        return $this->jsonResponse($totals);


    }
}
