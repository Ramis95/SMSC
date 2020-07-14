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


        if(app('redis'))
        {
            if($this->user_request['serviceNumber'])
            {
                $command =$this->get_command($this->user_request['serviceNumber']);

                if($command && method_exists($this, $command))
                {

                    $url = $this->billing_api_url . $command;

                    $user_response = $this->$command();

                    $this->return_response_to_client($user_response);

                    $log = "\n— request: {" . json_encode($this->user_request) . "} \n";
                    $log .= "— response code: {" . $user_response['response_code'] . "} \n";
                    $log .= "— response code: {" . $user_response['response_data'] . "} \n";
                    $log .= "————————————————————";
                    Log::info($log);


                }
                else
                {
                    /* Return message command or method not found */
                }

            }
            else
            {
                /* Return message invalid service number */
            }
        }
        else
        {
           /* return message about error */
        }

    }

    private function get_balance($url)
    {
        /* Get user balance from billing api */

        //$this->user_request['faza'] = app('redis')->get($this->user_request['sessionId']);


        $balance_data['msisdn'] = $this->user_request['msisdn'];
        $balance_data['sessionId'] = $this->user_request['sessionId'];
        $check_balance_url = $this->billing_api_url.'get_balance';

        $user_balance = $this->send_billing_api($check_balance_url, $balance_data);
        /* Проверить, все ли данные пришли */



        $has_bonus = $this->check_bonus($user_balance['NCLIENT']);
        /* Проверить, все ли данные пришли */

        if($has_bonus['nCount'] == 1)
        {
            $check_bonus_url = $this->billing_api_url.'get_bonus';
            $bonus_data['nClient'] = $user_balance['NCLIENT'];
            $user_bonus = $this->send_billing_api($check_bonus_url, $bonus_data);

            $text1 = Redis::hGet('*100#', 'text1');
            $text2 = Redis::hGet('*100#', 'text2');

            $text1 = str_replace('%bonus%',$user_bonus['vBallans'], $text1);
            $text2 = str_replace('%bonus%',$user_bonus['vBallans'], $text2);

        }

        if($user_balance['vCurrentBalance'] > 0 && $user_balance['vCurrentBalance'] < 2 && $user_balance['vCurrentBalance'] != 0)
        {
            $user_balance['vCurrentBalance'] = str_replace(',','0,', $user_balance['vCurrentBalance'] );
        }

        if($user_balance['vCurrentBalance'] < 25)
        {
            $text3 = Redis::hGet('*100#', 'text3');
            $text3 = str_replace('%balance%',$user_bonus['vBallans'], $text3);
            $text3 = str_replace('%text1%',$text1, $text3);

            /* отправка смс */

        }

        $text4 = Redis::hGet('*100#', 'text4');

        $response_text = str_replace('%balance%', $user_balance['vCurrentBalance'], $text4);
        $response_text = str_replace('%text2%', $text2, $response_text);

        $response['text'] = $response_text;
        $response['sessionId'] = $this->user_request['sessionId'];
        $response['endSession'] = 1;

        return json_encode($response_text);

        // $this->interactivity_check($this->user_request['sessionId'], $billing_response['response_data']);

    }

    private function check_bonus($nClient)
    {
        $url = $this->billing_api_url . 'check_bonus';
        $data['nClient'] = $nClient;
        $billing_response = $this->send_billing_api($url, $data);
        return $billing_response;
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
        Redis::hSet('*100#', 'command', 'get_user_balance');
        Redis::hSet('*100#', 'text1', 'На Вашем бонусном счете: %bonus% Подробнее lk.letai.ru');
        Redis::hSet('*100#', 'text2', 'Бонусный счет: %bonus%');
        Redis::hSet('*100#', 'text3', 'Необходимо пополнить счет, чтобы быть на связи! Баланс: %balance% text1');
        Redis::hSet('*100#', 'text4', 'Баланс: %balance% руб. %text2%');

    }

    private function return_response_to_client($response)
    {
        /* Return success response (json) to client */

        echo $response['response_data'];

    }

    private function get_command($serviceNumber)
    {
        /* Get command from Redis */

        $command = Redis::hGet($serviceNumber, 'command');
        return $command;

    }

    private function send_billing_api($url, $user_data)
    {
        /* Send request to billing-api */

        $json = json_encode($user_data);
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

        $result['response_data'] = curl_exec($ch);
        $result['response_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $result;

    }


}
