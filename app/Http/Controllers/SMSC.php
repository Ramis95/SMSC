<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Redis;

class SMSC extends Controller
{
    public function index()
    {

//        echo phpinfo();
//        die();


        app('redis')->set(1, 'one key');
        $test = app('redis')->get(1);
        dump($test);

        die();

        $user = Redis::get('user:profile:1');
        dd($user);
        die();

        app('redis')->exists(11);

        app('redis')->set(11, 'cAS');

        app('redis')->get(11);



//        require "predis/Autoloader.php";
//        Predis\Autoloader::register();
//        $redis = new Predis\Client('tcp://10.58.174.11:6479');
//        $redis->set('foo', 'bar');
//        echo $redis->get('foo').PHP_EOL;
//# phpredis code:
//        $redis2 = new Redis();
//        $redis2->connect('10.58.174.11', 6479);
//        $redis2->set('foo', 'bar2');
//        echo $redis2->get('foo').PHP_EOL;

//        app('redis');
//        Cache::get('test');
//        $redis = new Redis();
//        $redis->connect('localhost:6379');
//
//        Redis::set('name', 'Taylor');
//        $values = Redis::lrange('names', 5, 10);
//        dd($values);
//

//        dd('wqe');


//        require "predis/autoload.php";
//        Predis\Autoloader::register();
//
//        try {
//            $redis = new PredisClient();
//
//            // This connection is for a remote server
//            /*
//                $redis = new PredisClient(array(
//                    "scheme" => "tcp",
//                    "host" => "153.202.124.2",
//                    "port" => 6379
//                ));
//            */
//        }
//        catch (Exception $e) {
//            die($e->getMessage());
//        }
    }
}
