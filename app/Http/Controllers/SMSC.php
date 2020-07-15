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

        if(app('redis')) /* Check Redis */
        {

            if(isset($this->user_request['serviceNumber'])) /* Check input command */
            {
                $command = $this->get_command($this->user_request['serviceNumber']);

                if($command && method_exists($this, $command)) /* Check command && method for command */
                {
                    $user_response = $this->$command(); /* Call command USSD/SMS */
                    $this->return_response_to_client($user_response);

                    $log = "\n— request: {" . json_encode($this->user_request) . "} \n";
                    $log .= "— response: {" . $user_response . "} \n";
                    $log .= "————————————————————";
                    Log::info($log);
                }
                else
                {
                    if(!isset($this->user_request['sessionId']))
                    {
                        $this->user_request['sessionId'] = NULL;
                    }

                    return response()->json([
                        'text' => 'Такой команды не существует',
                        'sessionId' => $this->user_request['sessionId'],
                        'endSession' => '1',
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
                    'text' => 'Команда не передана',
                    'sessionId' => $this->user_request['sessionId'],
                    'endSession' => '1',
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
            if(!isset($this->user_request['sessionId']))
            {
                $this->user_request['sessionId'] = NULL;
            }

            return response()->json([
                'text' => 'Невозможно получить информацию по Вашему номеру.',
                'sessionId' => $this->user_request['sessionId'],
                'endSession' => '1',
            ], 400);
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
            return response()->json([
                'text' => 'Невозможно получить информацию по Вашему номеру.',
                'sessionId' => $this->user_request['sessionId'],
                'endSession' => '1',
            ], 500);
        }

        $has_bonus = $this->check_bonus($user_balance['nClient']);
        $has_bonus = json_decode($has_bonus['response_data'], true);

        /* Check bonus data */
        if(!isset($has_bonus['nClient']) || !isset($has_bonus['nCount']))
        {
            return response()->json([
                'text' => 'Невозможно получить информацию по Вашему номеру.',
                'sessionId' => $this->user_request['sessionId'],
                'endSession' => '1',
            ], 500);
        }


        if($has_bonus['nCount'] > 0 && $has_bonus['nCount'] < 2)
        {
            $user_bonus = $this->get_bonus($user_balance['nClient']);
            $user_bonus = json_decode($user_bonus['response_data'], true);

            /* Check user bonus */
            if(!isset($user_bonus['vBallans']))
            {
                return response()->json([
                    'text' => 'Невозможно получить информацию по Вашему номеру.',
                    'sessionId' => $this->user_request['sessionId'],
                    'endSession' => '1',
                ], 500);
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

        return json_encode($response);

        // $this->interactivity_check($this->user_request['sessionId'], $billing_response['response_data']);

    }

    public function send_SMS()
    {
        $url = $this->billing_api_url . 'sendsms';
        $send_sms_response = $this->send_billing_api($url, $this->user_request);

//        $log = "\n— request: {" . json_encode($this->user_request) . "} \n";
//        $log .= "— response: {" . $send_sms_response['response_data'] . "} \n";
//        $log .= "————————————————————";
//        Log::info($log);

        $this->return_response_to_client($send_sms_response['response_data']);
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
        Redis::hSet('*100#', 'command', 'get_balance');
        Redis::hSet('*100#', 'text1', 'На Вашем бонусном счете: %bonus%. Подробнее lk.letai.ru');
        Redis::hSet('*100#', 'text2', 'Бонусный счет: %bonus%.');
        Redis::hSet('*100#', 'text3', 'Необходимо пополнить счет, чтобы быть на связи! Баланс: %balance% руб. %text1%');
        Redis::hSet('*100#', 'text4', 'Баланс: %balance% руб. %text2%');

    }

    private function return_response_to_client($response)
    {
        /* Return success response (json) to client */

        echo $response;
        return true;

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
