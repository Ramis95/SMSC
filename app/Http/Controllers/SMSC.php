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
                    $url = 'http://127.0.0.1:81/v1/' . $command;
                    $billing_response = $this->send_billing_api($url, $this->user_request);
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

//        dump(app('redis'));





        die();


//        app('redis')->set(1, 'one key');
//        app('redis')->del(1);
//        $test = app('redis')->get(1);
//        dump($test);

        die();



        die();

        app('redis')->exists(11);

        app('redis')->set(11, 'cAS');

        app('redis')->get(11);


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

//        var_dump($url);
//        var_dump($user_data);
//        die();

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
        $result['response_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Получаем HTTP-код

        var_dump($ch);
        var_dump($result);

    }


}
