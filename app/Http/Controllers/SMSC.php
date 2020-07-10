<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;


class SMSC extends Controller
{

    private $user_request;

    public function __construct(Request $request)
    {
        /* input data */

        $this->user_request = json_decode($request->getContent(), true);
    }

    public function index()
    {
        /* Get a command from Redis and send a request to the billing API */

        if(app('redis'))
        {
            if($this->user_request['serviceNumber'])
            {

                $command =$this->get_command($this->user_request['serviceNumber']);
                if($command)
                {
                    $url = 'http://192.168.143.207/v1/' . $command;

                    $this->user_request['faza'] = app('redis')->get($this->user_request['sessionId']);




                    $billing_response = $this->send_billing_api($url, $this->user_request);

                    $this->interactivity_check($this->user_request['sessionId'], $billing_response['response_data']);









                }
                else
                {
                    /* Return message command not found */
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

        die();


//        app('redis')->set(1, 'one key');
//        app('redis')->del(1);
//        $test = app('redis')->get(1);
//        dump($test);


    }


    private function interactivity_check($sessionID, $response_data)
    {
        /* Verifying the existence of an interactive request for sessionId */

//        die();

        $response_data = json_decode($response_data);
//        var_dump($response_data);

        $sessionInMemory = app('redis')->get($sessionID);
//        var_dump($sessionInMemory);

//        var_dump($response_data->endSession);

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


    private function get_command($serviceNumber)
    {
        /* Get command from Redis */

        $command = app('redis')->get($serviceNumber);
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
