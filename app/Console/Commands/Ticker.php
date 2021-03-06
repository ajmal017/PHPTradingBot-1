<?php

namespace App\Console\Commands;

use App\BithumbTradeHelper;
use App\Modules;
use App\Price;
use App\Setting;
use App\Ticker as TickerModel;
use Bg\Sdk\WS\WSResponse;
use Bg\Sdk\WS\Streams\TickerStream;
use Bg\Sdk\BithumbGlobalClient;
use Bg\Sdk\WS\Interfaces\WSClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use \Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
class Ticker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'daemon:ticker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listens to thicker web socket';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {
        $bithumb = BithumbTradeHelper::getBithumb();
        $tickerType = Setting::getValue('tickerType', 'wss');
        $saveTicker = Setting::getValue('saveTicker', true);
        $enabledModules = Modules::getActiveModules();
        $eligibleModules = [];
        if ($enabledModules) {
            foreach ($enabledModules as $module) {
                $_module = $module->getFactory();
                if (method_exists($_module, 'signalLoop')) {
                    $eligibleModules[] = $_module;
                }
            }
        }
        $bithumb->subscribe(new TickerStream(
            'BIP-USDT',
            function (WSClientInterface $client,TickerStream $stream ,WSResponse $response) use ($saveTicker,$eligibleModules,$tickerType) {
                if ($response->isError()) {
                    error_log(print_r($response,1));
                    $client->subscribe($stream); // reconnect
                }
                if ($response->isNormal()) {
//                    error_log(print_r($response->getData(), 1));
                    try {
                        //convert ticker data to store
                        if ($response === 'close') {
                            $this->info('Daemon ticker restart');
                            return Artisan::call("daemon:ticker", []);
                        }
                        //

                        $data =$response->getData();
                        $newTicker = new TickerModel();
                        $newTicker->eventType = $response->getTopic();
                        $newTicker->eventTime = $response->getTimestamp();
                        if(is_array($data)){
                            foreach ($data as $tick){
                                $newTicker->symbol = $tick->symbol;
                                $newTicker->close = doubleval($tick->c);
                                if ($tickerType == 'full') {
                                    $newTicker->numTrades = intval($tick->v);
                                    $newTicker->low = doubleval($tick->l);
                                    $newTicker->high = doubleval($tick->h);
                                    $newTicker->percentChange = floatval($tick->p);
                                }
                                if ($saveTicker) {
                                    try {
                                        //                        \App\Ticker::create($ticker);

                                        $newTicker->save();
//                                error_log(print_r($save));
//                            $this->info('2|'.print_r($newTicker,1));

                                        // Closures include ->first(), ->get(), ->pluck(), etc.
                                    } catch (QueryException $ex) {
                                        $this->info('New Ticker Not add to DB' . $ex->getMessage());

//                            dd($ex->getMessage());
                                        // Note any method of class PDOException can be called on $ex.
                                    }

                                }
                                Cache::put($newTicker->symbol, $newTicker, now()->addHour(1));
                                $this->onTickEvent($newTicker, $eligibleModules);
                            }
                        }else{
                            $newTicker->symbol = $data->symbol;
                            $newTicker->close = doubleval($data->c);
                            if ($tickerType == 'full') {
                                $newTicker->numTrades = intval($data->v);
                                $newTicker->low = doubleval($data->l);
                                $newTicker->high = doubleval($data->h);
                                $newTicker->percentChange = floatval($data->p);
                            }
                            if ($saveTicker) {
                                try {
                                    //                        \App\Ticker::create($ticker);

                                    $newTicker->save();
//                                error_log(print_r($save));
//                            $this->info('2|'.print_r($newTicker,1));

                                    // Closures include ->first(), ->get(), ->pluck(), etc.
                                } catch (QueryException $ex) {
                                    $this->info('New Ticker Not add to DB' . $ex->getMessage());

//                            dd($ex->getMessage());
                                    // Note any method of class PDOException can be called on $ex.
                                }

                            }
                            Cache::put($newTicker->symbol, $newTicker, now()->addHour(1));
                            $this->onTickEvent($newTicker, $eligibleModules);
                        }

//                    $this->info('3|'.$newTicker->close);


                    } catch (\Exception $exception) {
                        $this->alert($exception->getMessage());
                    }

                    Cache::forever('lastTick', time());
                }
            }));

//        } else {
//            $this->info('WS : Mini Ticker');
//            $bithumb->miniTicker(function ($api, $ticker) use ($saveTicker,$eligibleModules) {
//                try {
//                    if ($saveTicker)
//                        \App\Ticker::create($ticker);
//                    foreach ($ticker as $tick) {
//                        Cache::put($tick['symbol'], $tick, now()->addHour(1));
//                        $this->onTickEvent($tick,$eligibleModules);
//                    }
//                } catch (\Exception $exception) {
//                    $this->alert($exception->getMessage());
//                }
//
//                Cache::forever('lastTick', time());
//            });
//        }

        unset($bithumb);
        $this->info('Stop cycle return 0');
        return 0;
    }

    public function onTickEvent($tick, $eligibleModules)
    {
        foreach ($eligibleModules as $module) {
            $module->onTick($tick);
        }
    }
}
