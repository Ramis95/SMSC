<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SMSC extends Controller
{

    private $user_request;
    private $billing_api_url;

    public function __construct(Request $request)
    {
        /* input data */

        $this->user_request = json_decode($request->getContent(), true);
        $this->billing_api_url = 'http://192.168.143.207/v1/';
    }

    public function index()
    {
        /* Get a command from Redis and send a request to the billing API */

        /* Check sessionId */
        if(!isset($this->user_request['sessionId']))
        {
            $this->user_request['sessionId'] = NULL;
        }

        if(app('redis')) /* Check Redis */
        {
            if(isset($this->user_request['serviceNumber'])) /* Check input command */
            {
                $command = $this->get_command($this->user_request['serviceNumber']);

                if($command && method_exists($this, $command)) /* Check command && method for command */
                {
                    $response = $this->$command(); /* Call command USSD/SMS */

                    $log = "\n— request: " . json_encode($this->user_request) . " \n";
                    $log .= "— response: " . json_encode($response) . " \n";
                    $log .= "————————————————————";
                    Log::info($log);

                    /* Return response to the client */
                    return response()->json([
                        'text' => $response['text'],
                        'sessionId' => $response['sessionId'],
                        'endSession' => $response['endSession'],
                    ], $response['code']);
                }
                else
                {
                    return response()->json([
                        'text' => 'Такой команды не существует',
                        'sessionId' => $this->user_request['sessionId'],
                        'endSession' => 1,
                    ], 400);
                }
            }
            else
            {
                return response()->json([
                    'text' => 'Невозможно получить информацию по Вашему номеру.',
                    'sessionId' => $this->user_request['sessionId'],
                    'endSession' => 1,
                ], 400);

            }
        }
        else
        {
            if(!isset($this->user_request['sessionId']))
            {
                $this->user_request['sessionId'] = NULL;
            }

            return response()->json([
                'text' => 'Ошибка с Redis',
                'sessionId' => $this->user_request['sessionId'],
                'endSession' => '1',
            ], 500);
        }

    }

    private function get_balance()
    {
        /* Get user balance from billing api */

        //$this->user_request['faza'] = app('redis')->get($this->user_request['sessionId']);

        $user_bonus = [];
        $text1 = ''; /* text - bonus for sms */
        $text2 = ''; /* text - bonus for push */
        $text3 = ''; /* final text - for sms */
        $text4 = ''; /* text - balance */


        /* Check input data */
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        /* Check 7 at the beginning*/
        if (substr($this->user_request['msisdn'], 0,1) == 7 && strlen($this->user_request['msisdn']) == 11)
        {
            $this->user_request['msisdn'] = (int)substr($this->user_request['msisdn'], 1);
        }

        $balance_data['msisdn'] = $this->user_request['msisdn'];
        $balance_data['sessionId'] = $this->user_request['sessionId'];
        $check_balance_url = $this->billing_api_url.'get_balance';
        $user_balance = $this->send_billing_api($check_balance_url, $balance_data);
        $user_balance = json_decode($user_balance['response_data'], true);

        /* Check balance data */
        if(!isset($user_balance['vCurrentBalance']) || !isset($user_balance['nClient']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 500;

            return $response;
        }

        $has_bonus = $this->check_bonus($user_balance['nClient']);
        $has_bonus = json_decode($has_bonus['response_data'], true);

        /* Check bonus data */
        if(!isset($has_bonus['nClient']) || !isset($has_bonus['nCount']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 500;

            return $response;
        }


        if($has_bonus['nCount'] > 0 && $has_bonus['nCount'] < 2)
        {
            $user_bonus = $this->get_bonus($user_balance['nClient']);
            $user_bonus = json_decode($user_bonus['response_data'], true);

            /* Check user bonus */
            if(!isset($user_bonus['vBallans']))
            {
                $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
                $response['sessionId'] = $this->user_request['sessionId'];
                $response['endSession'] = 1;
                $response['code'] = 500;

                return $response;
            }

            $text1 = Redis::hGet('*100#', 'text1');
            $text2 = Redis::hGet('*100#', 'text2');

            $text1 = str_replace('%bonus%',$user_bonus['vBallans'], $text1);
            $text2 = str_replace('%bonus%',$user_bonus['vBallans'], $text2);
        }

        if($user_balance['vCurrentBalance'] >= -1 && $user_balance['vCurrentBalance'] <= 1 && $user_balance['vCurrentBalance'] != 0)
        {
            $user_balance['vCurrentBalance'] = str_replace(',','0,', $user_balance['vCurrentBalance'] );
        }

        if($user_balance['vCurrentBalance'] < 25)
        {
            $text1 = Redis::hGet('*100#', 'text1');
            $text1 = str_replace('%bonus%',$user_bonus['vBallans'], $text1);

            $text3 = Redis::hGet('*100#', 'text3');

            $text3 = str_replace('%balance%',$user_balance['vCurrentBalance'], $text3);
            $text3 = str_replace('%text1%',$text1, $text3);


            $smsData['MSISDN'] = $this->user_request['msisdn'];
            $smsData['SMSText'] = $text3;
            $smsData['HeaderName'] = "Letai";
            $smsData['BulkId'] = 69;
            $smsData['Delivery_type'] = 0;

            $url = $this->billing_api_url . 'sendsms';
            $this->send_billing_api($url, $smsData);

        }

        $text4 = Redis::hGet('*100#', 'text4');

        $response_text = str_replace('%balance%', $user_balance['vCurrentBalance'], $text4);
        $response_text = str_replace('%text2%', $text2, $response_text);

        $response['text'] = $response_text;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;

        // $this->interactivity_check($this->user_request['sessionId'], $billing_response['response_data']);

    }

    private function get_my_number()
    {
        /* Get the number and return */

        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $text1 = Redis::hGet('*116*106#', 'text1');
        $tail1 = Redis::hGet('tails', 'tail1');
        $text2 = Redis::hGet('*116*106#', 'text2');
        $text2 .= $this->user_request['msisdn'] . '. ' . $tail1;

        $smsData['MSISDN'] = $this->user_request['msisdn'];
        $smsData['SMSText'] = $text2;
        $smsData['HeaderName'] = "Letai";
        $smsData['BulkId'] = 1;
        $smsData['Delivery_type'] = 0;

        /* Send sms to client */
        $url = $this->billing_api_url . 'sendsms';
        $this->send_billing_api($url, $smsData);

        $response['text'] = $text1;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;

    }

    private function get_my_tariff()
    {
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $tariff_data['msisdn'] = $this->user_request['msisdn'];
        $tariff_data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'get_tariff';
        $user_tariff = $this->send_billing_api($url, $tariff_data);

        $user_tariff = json_decode($user_tariff['response_data'], true);

        /* Check bonus data */
        if(!isset($user_tariff['smsText']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 500;

            return $response;
        }


        /* Send sms */
        $smsData['MSISDN'] = $this->user_request['msisdn'];
        $smsData['SMSText'] = $user_tariff['smsText'];
        $smsData['HeaderName'] = "Letai";
        $smsData['BulkId'] = 92;
        $smsData['Delivery_type'] = 0;

        $url = $this->billing_api_url . 'sendsms';
        $this->send_billing_api($url, $smsData);

        $text1 = Redis::hGet('*116*100#', 'text1');
        $response['text'] = $text1;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;

    }

    private function get_balance_of_tariff()
    {
        /* Отложено */
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }


        $data['msisdn'] = $this->user_request['msisdn'];
        $data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'get_tariff_balance';
        $user_tariff_balance = $this->send_billing_api($url, $data);

        $user_tariff_balance = json_decode($user_tariff_balance['response_data'], true);

    }

    private function get_family_cashback()
    {
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $data['msisdn'] = $this->user_request['msisdn'];
        $data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'get_family_cashback';
        $additional_number = $this->send_billing_api($url, $data);

        $additional_number = json_decode($additional_number['response_data'], true);

        if(!isset($additional_number["family_cashback"]))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 500;

            return $response;
        }
        $family_cashback = json_decode($additional_number["family_cashback"], true);

        $month = $this->get_month_name();

        $text1 = Redis::hGet('*103#', 'text1');
        $text1 = str_replace('%month%', $month, $text1);
        $text1 = str_replace('%sum%', $family_cashback['sum'], $text1);

        $smsData['MSISDN'] = $this->user_request['msisdn'];
        $smsData['SMSText'] = $text1;
        $smsData['HeaderName'] = "Letai";
        $smsData['BulkId'] = 88;
        $smsData['Delivery_type'] = 0;

        /* Send sms to client */
        $url = $this->billing_api_url . 'sendsms';
        $this->send_billing_api($url, $smsData);

        $text2 = Redis::hGet('*103#', 'text2');
        $response['text'] = $text2;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;

    }

    private function check_additional_number()
    {
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $data['msisdn'] = $this->user_request['msisdn'];
        $data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'check_additional_number';
        $additional_number = $this->send_billing_api($url, $data);

        $additional_number = json_decode($additional_number['response_data'], true);

        if($additional_number['num'])
        {
            $text2 = Redis::hGet('*116*718#', 'text2');
            $text2 .= $additional_number['num'];
            $response['text'] = $text2;
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 200;
        }
        else
        {
            $text1 = Redis::hGet('*116*718#', 'text1');
            $response['text'] = $text1;
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 200;
        }

        return $response;


    }

    private function service_together_beneficial()
    {
        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $data['msisdn'] = $this->user_request['msisdn'];
        $data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'service_together_beneficial';
        $service_together_beneficial = $this->send_billing_api($url, $data);
        $benefit = json_decode($service_together_beneficial['response_data'], true);

        $text1 = Redis::hGet('*116*117#', 'text1');
        $text1 = str_replace('%nSUM%', $benefit['nSum'], $text1);

        $response['text'] = $text1;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;

    }

    public function home_cashback()
    {
        /* Сделать все проверки */

        $sms_text = '';

        if(!isset($this->user_request['msisdn']) || !isset($this->user_request['sessionId']))
        {
            $response['text'] = 'Невозможно получить информацию по Вашему номеру.';
            $response['sessionId'] = $this->user_request['sessionId'];
            $response['endSession'] = 1;
            $response['code'] = 400;

            return $response;
        }

        $data['msisdn'] = $this->user_request['msisdn'];
        $data['sessionId'] = $this->user_request['sessionId'];
        $url = $this->billing_api_url.'home_cashback';
        $cashback = $this->send_billing_api($url, $data);
        $cashback = json_decode($cashback['response_data'], true);
        $home_cashback = json_decode($cashback['home_cashback'], true);

        if($home_cashback['sum'] === 0)
        {
            $sms_text = Redis::hGet('*104#', 'text1');
        }
        else
        {
            $month = $this->get_month_name();
            $sms_text = Redis::hGet('*104#', 'text2');
            $sms_text = str_replace('%MON_TH%',$month, $sms_text);
            $sms_text = str_replace('%SU_M%',$home_cashback['sum'] , $sms_text);
        }

        $smsData['MSISDN'] = $this->user_request['msisdn'];
        $smsData['SMSText'] = $sms_text;
        $smsData['HeaderName'] = "Letai";
        $smsData['BulkId'] = 89;
        $smsData['Delivery_type'] = 0;

        /* Send sms to client */
        $url = $this->billing_api_url . 'sendsms';
        $this->send_billing_api($url, $smsData);


        $text3 = Redis::hGet('*104#', 'text3');
        $response['text'] = $text3;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;
        $response['code'] = 200;

        return $response;
    }

    public function send_SMS()
    {
        $url = $this->billing_api_url . 'sendsms';
        $send_sms_response = $this->send_billing_api($url, $this->user_request);

        $log = "\n— request: {" . json_encode($this->user_request) . "} \n";
        $log .= "— response: {" . $send_sms_response['response_data'] . "} \n";
        $log .= "————————————————————";
        Log::info($log);

        $this->return_response_to_client($send_sms_response['response_data']);
    }

    private function get_month_name()
    {
        $mlist = array(
            "1"=>"январь","2"=>"февраль","3"=>"март",
            "4"=>"апрель","5"=>"май", "6"=>"июнь",
            "7"=>"июль","8"=>"август","9"=>"сентябрь",
            "10"=>"октябрь","11"=>"ноябрь","12"=>"декабрь");

        return $mlist[date("n")];
    }

    private function check_bonus($nClient)
    {
        $url = $this->billing_api_url . 'check_bonus';
        $data['nClient'] = $nClient;
        $billing_response = $this->send_billing_api($url, $data);
        return $billing_response;
    }

    private function get_bonus($nClient)
    {
        $url = $this->billing_api_url.'get_bonus';
        $bonus_data['nClient'] = $nClient;
        $user_bonus = $this->send_billing_api($url, $bonus_data);
        return $user_bonus;
    }

    private function interactivity_check($sessionID, $response_data)
    {
        /* Verifying the existence of an interactive request for sessionId */

        $response_data = json_decode($response_data);

        $sessionInMemory = app('redis')->get($sessionID);

        if($sessionInMemory && $response_data->endSession == 1)
        {
            app('redis')->del($sessionID);
        }
        elseif ($sessionInMemory && $response_data->endSession == 0)
        {
            $faza = $sessionInMemory + $response_data->interactive_result;
            app('redis')->set($sessionID, $faza, 'EX', 30);
        }
        elseif (!$sessionInMemory && $response_data->endSession == 0 )
        {
            app('redis')->set($sessionID,1, 'EX', 30);
        }
    }

    public function save_command_in_redis()
    {
        /* Balance */
        Redis::hSet('*100#', 'command', 'get_balance');
        Redis::hSet('*100#', 'text1', 'На Вашем бонусном счете: %bonus%. Подробнее lk.letai.ru');
        Redis::hSet('*100#', 'text2', 'Бонусный счет: %bonus%.');
        Redis::hSet('*100#', 'text3', 'Необходимо пополнить счет, чтобы быть на связи! Баланс: %balance% руб. %text1%');
        Redis::hSet('*100#', 'text4', 'Баланс: %balance% руб. %text2%');

        /* Test tmt */
        Redis::hSet('*556#', 'command', 'get_balance');
        Redis::hSet('*556#', 'text1', 'На Вашем бонусном счете: %bonus%. Подробнее lk.letai.ru');
        Redis::hSet('*556#', 'text2', 'Бонусный счет: %bonus%.');
        Redis::hSet('*556#', 'text3', 'Необходимо пополнить счет, чтобы быть на связи! Баланс: %balance% руб. %text1%');
        Redis::hSet('*556#', 'text4', 'Баланс: %balance% руб. %text2%');

        /* My number */
        Redis::hSet('*116*106#', 'command', 'get_my_number');
        Redis::hSet('*116*106#', 'text1', 'Вам направлено смс сообщение');
        Redis::hSet('*116*106#', 'text2', 'Ваш номер: 7');

        /* My tariff */
        Redis::hSet('*116*100#', 'command', 'get_my_tariff');
        Redis::hSet('*116*100#', 'text1', 'Вам направлено смс сообщение');

//        /* Installments */
//        Redis::hSet('*116*116#', 'command', 'check_installments');
//        Redis::hSet('*116*116#', 'text1', 'Вам направлено смс сообщение');
//        Redis::hSet('*116*116#', 'text2', 'Сумма рассрочки составляет %installments_summ% руб. До 25го числа по лицевому счету %account_num% подлежит внесению ежемесячный платеж в размере %debt% за рассрочку мобильного оборудования 4G.');

        /* Additional number */
        Redis::hSet('*116*718#', 'command', 'check_additional_number');
        Redis::hSet('*116*718#', 'text1', 'У Вас нет подключенных дополнительных номеров!');
        Redis::hSet('*116*718#', 'text2', 'Ваш дополнительный номер: ');

        /* Balance of tariff */
        Redis::hSet('*116*700#', 'command', 'get_balance_of_tariff');

        /* Family cashback */
        Redis::hSet('*103#', 'command', 'get_family_cashback');
        Redis::hSet('*103#', 'text1', 'Ваш текущий семейный кэшбэк за %month% составляет %sum% рублей. Будьте на связи, чтобы получить кэшбэк или даже больше в конце месяца!');
        Redis::hSet('*103#', 'text2', 'Запрос отправлен');

        /* Вместе выгодно */
        Redis::hSet('*116*117#', 'command', 'service_together_beneficial');
        Redis::hSet('*116*117#', 'text1', 'Вам оказаны услуги на %nSUM% руб.');

        /* Домашний кэшбэк */
        Redis::hSet('*104#', 'command', 'home_cashback');
        Redis::hSet('*104#', 'text1', 'Кэшбэк по программе "Вместе выгодно 2.0" в текущем месяце не может быть расчитан, так как не выполнены условия программы, подробнее +78432222222');
        Redis::hSet('*104#', 'text2', 'Ваш текущий домашний кэшбэк за %MON_TH% составляет %SU_M% рублей. Будьте на связи, чтобы получить кэшбэк или даже больше в конце месяца! Сумма может быть изменена в соответствии с условиями программы "Вместе выгодно 2.0".');
        Redis::hSet('*104#', 'text3', 'Запрос отправлен');

        /* Tails */
        Redis::hSet('tails', 'tail1', '// Красивые мобильные номера за 0 руб. Онлайн заказ в два клика tattelecom.ru/mobile/number');
    }

    private function get_command($serviceNumber)
    {
        /* Get command from Redis */

        $command = Redis::hGet($serviceNumber, 'command');
        return $command;
    }

    private function send_billing_api($url, $data)
    {
        /* Send request to billing-api */

        $json = json_encode($data);
        $ch   = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true); /* Send post request */
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json); /* json = user data */
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,
            false); /* Disable certificate */
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            )
        );

//        var_dump(curl_exec($ch));

        $result['response_data'] = curl_exec($ch);
        $result['response_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $result;

    }


}
