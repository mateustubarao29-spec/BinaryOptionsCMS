<?php

namespace App\Services\MainHttp;

use App\Events\ChangeBalance;
use App\Events\ChangeContestBalance;
use App\Events\ChangeDemoBalance;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\MainHttp\Trading\BuySymbolRequest;
use App\Http\Requests\MainHttp\Trading\DemoTradingHistoryRequest;
use App\Http\Requests\MainHttp\Trading\TradingHistoryRequest;
use App\MarketStatus;
use App\Models\Contest;
use App\Models\ContestOpenOrder;
use App\Models\ContestUser;
use App\Models\LatestDemoOrder;
use App\Models\LatestOrder;
use App\Models\OpenDemoOrders;
use App\Models\OpenOrders;
use App\Models\Symbols\Options\Symbol;
use App\Models\Symbols\Options\Ticks;
use App\Models\SymbolShortStatistic;
use App\Models\UserTodayStatistic;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\DataTables;

class TradingService
{
    public function getAuthTokenServ(Request $request)
    {
        return Cache::remember('authTokenServ_v2', 3600 * 24, function () {
            $login = config('tradingview.login');
            $password = config('tradingview.password');

            // Public chart data can use TradingView's anonymous token.
            // Do not attempt an empty sign-in when no account credentials are configured.
            if (empty($login) || empty($password)) {
                return 'unauthorized_user_token';
            }

            $cookieFile = __DIR__ . '/cookie.txt';
            $authToken = '';

            if (file_exists($cookieFile)) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'https://www.tradingview.com/quote_token/',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_COOKIEJAR => $cookieFile,
                    CURLOPT_ENCODING => 'gzip',
                    CURLOPT_TIMEOUT => 15,
                ]);
                $authToken = (string) curl_exec($ch);
                curl_close($ch);
                $authToken = trim($authToken, '"');
            }

            if (strlen($authToken) < 150) {
                $headers = [
                    'accept: */*',
                    'content-type: application/x-www-form-urlencoded',
                    'origin: https://www.tradingview.com',
                    'referer: https://www.tradingview.com/',
                    'user-agent: Mozilla/5.0',
                    'x-language: en',
                    'x-requested-with: XMLHttpRequest',
                ];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_URL => 'https://www.tradingview.com/accounts/signin/',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_COOKIEJAR => $cookieFile,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_ENCODING => 'gzip',
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'feature_source' => 'Header',
                        'username' => $login,
                        'password' => $password,
                        'remember' => 'on',
                    ]),
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $json = json_decode((string) $response);
                $authToken = $json->user->auth_token ?? '';
            }

            return strlen($authToken) >= 150 ? $authToken : 'unauthorized_user_token';
        });
    }

    public function buySymbolServ(BuySymbolRequest $request)
    {
        try {
            $stringExplode = explode(":", $request->symbol);

            $symbol = Symbol::where('symbol', $stringExplode[1])->where('broker', $stringExplode[0])->firstOrFail();
            if ((Carbon::now()->hour >= $symbol->work_to or Carbon::now()->hour < $symbol->work_from) and ($symbol->work_from != $symbol->work_to)) {
                return (['data' => ['message' => __('locale.trading_non_work_time')], 'status' => 422]);
            }
            $seconds = $request->hours * 60 * 60 + $request->minutes * 60 + $request->seconds;
            if ((Carbon::now()->addSeconds($seconds)->hour > $symbol->work_to or Carbon::now()->hour < $symbol->work_from) and ($symbol->work_from != $symbol->work_to)) {
                return (['data' => ['message' => __('locale.trading_expiration_more')], 'status' => 422]);
            }

            if ($seconds < $symbol->min_expiration_time) {
                return ['data' => ['message' => __('locale.trading_min_30_seconds', ['min_expiration' => self::formatSecondsServ($symbol->min_expiration_time)])], 'status' => 422];
            }
            if ($request->type == 'real') {
                if (Auth::user()->balance - $request->amount < 0) {
                    return (['data' => ['message' => __('locale.trading_not_enough_money')], 'status' => 422]);
                }
            } elseif ($request->type == 'demo') {
                if (Auth::user()->demo_balance - $request->amount < 0) {
                    return (['data' => ['message' => __('locale.trading_not_enough_money')], 'status' => 422]);
                }
            } elseif ($request->type == 'tournament') {
                $contest = Contest::where('id', $request->id)->first();
                if (!$contest) {
                    return (['data' => ['message' => __('locale.tournament_trading_not_exist')], 'status' => 422]);
                }
                $contest_user = ContestUser::where('user_id', Auth::user()->id)->where('contest_id', $request->id)->first();
                if (!$contest_user) {
                    return (['data' => ['message' => __('locale.tournament_trading_already_registered')], 'status' => 422]);
                }
                if ($contest_user->banned) {
                    return (['data' => ['message' => __('locale.tournament_trading_blocked')], 'status' => 422]);
                }
                if ($contest_user->balance - $request->amount < 0) {
                    return (['data' => ['message' => __('locale.trading_not_enough_money')], 'status' => 422]);
                }
            }
            $market = MarketStatus::where('symbol_id', $request->symbol);
            if (!isset($market) || !$market->count()) {
                return (['data' => ['message' => __('locale.trading_place_error')], 'status' => 422]);
            }
            if ($market->first()->market_status != 'market') {
                return (['data' => ['message' => __('locale.trading_current_symbol_closed')], 'status' => 422]);
            }
            if ($request->type == 'real') { // Если у человека высокая прибыль немного замедляем выставление сделки
                $todayStat = UserTodayStatistic::where('user_id', Auth::user()->id)->first();
                if ($todayStat !== null) {
                    $profit = $todayStat->profit;
                    $loss = $todayStat->loss;
                    $total = $profit + $loss;
                    $percent = ($profit / $total) * 100;
                    if ($total > 10 and $percent >= 65) {
                        usleep(mt_rand(500000, 3000000));
                    }
                }
            }
            $symbols_all = Cache::remember('symbols_all', 60, function () {
                return Symbol::orderBy('percent', 'desc')->get();
            });
            $fisrt = Ticks::where('symbol_id', $request->symbol)->orderBy('created_at', 'desc')->first();
            if ($fisrt) {
                $price = $fisrt->price;
            } else {
                return (['data' => ['message' => __('locale.trading_place_error_2')], 'status' => 422]);
            }
            $hedge = 0;
            if (OpenOrders::where('user_id', Auth::user()->id)->where('symbol_id', $request->symbol)->where('type', '<>', $request->direction)->count()) {
                $hedge = 1;
            }
            if ($request->type == 'real') { // Если реальный счет
                $model = new OpenOrders();
                User::where('id', Auth::user()->id)->update(['balance' => DB::raw('balance-' . $request->amount)]);
                broadcast(new ChangeBalance(Auth::user()->balance - $request->amount, Auth::user()));
            } elseif ($request->type == 'demo') {
                $model = new OpenDemoOrders();
                User::where('id', Auth::user()->id)->update(['demo_balance' => DB::raw('demo_balance-' . $request->amount)]);
                broadcast(new ChangeDemoBalance(Auth::user()->demo_balance - $request->amount, Auth::user()));
            } elseif ($request->type == 'tournament') {
                $model = new ContestOpenOrder();
                $model->contest_id = $request->id;
                ContestUser::where('user_id', Auth::user()->id)->where('contest_id', $request->id)->update([
                    'balance' => DB::raw('balance-' . $request->amount)
                ]);
                $user_contest = ContestUser::where('user_id', Auth::user()->id)->where('contest_id', $request->id)->first();
                broadcast(new ChangeContestBalance($user_contest->balance, $user_contest, $request->id));
            }

            $model->symbol_id = $request->symbol;
            $model->user_id = Auth::user()->id;
            $model->type = $request->direction;
            $model->open_price = $price;
            $model->hedging = $hedge;
            $model->percent = $symbol->percent;
            $model->close_at = Carbon::now()->addSeconds($seconds)->format('Y-m-d H:i:s.u');
            $model->created_at = Carbon::now()->format('Y-m-d H:i:s.u');
            $model->amount = $request->amount;
            $model->save();
            $model->expiration = $seconds - 1;
            $model->timestamp = Carbon::parse($model->close_at)->timestamp;
            return ['data' => $model, 'status' => 200];
        } catch (\Throwable $exception) {
            dd($exception->getMessage());
        }
    }

    public function getOpenOrdersServ(Request $request)
    {
        return OpenOrders::where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get()->filter(function ($item) {
            $diff = date_diff(new \DateTime($item->close_at), new \DateTime($item->created_at));
            $item['expiration'] = $diff->s + $diff->i * 60 + $diff->h * 60 * 60;
            return $item;
        })->filter(function ($item) {
            $diff = date_diff(new \DateTime(), new \DateTime($item->created_at));
            $item['timestamp'] = Carbon::parse($item->close_at)->timestamp;
            return $item;
        });
    }

    public function getLatestOrdersServ(Request $request)
    {
        return LatestOrder::where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get()->filter(function ($item) {
            $diff = date_diff(new \DateTime($item->close_at), new \DateTime($item->open_at));
            $item['expiration'] = sprintf("%'.02d", $diff->h) . ':' . sprintf("%'.02d", $diff->i) . ':' . sprintf("%'.02d", $diff->s);
            return $item;
        });
    }

    public function getOpenDemoOrdersServ(Request $request)
    {
        return OpenDemoOrders::where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get()->filter(function ($item) {
            $diff = date_diff(new \DateTime($item->close_at), new \DateTime($item->created_at));
            $item['expiration'] = $diff->s + $diff->i * 60 + $diff->h * 60 * 60;
            return $item;
        })->filter(function ($item) {
            $diff = date_diff(new \DateTime(), new \DateTime($item->created_at));
            $item['timestamp'] = Carbon::parse($item->close_at)->timestamp;
            return $item;
        });
    }

    public function getLatestDemoOrdersServ(Request $request)
    {
        return LatestDemoOrder::where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get()->filter(function ($item) {
            $diff = date_diff(new \DateTime($item->close_at), new \DateTime($item->open_at));
            $item['expiration'] = sprintf("%'.02d", $diff->h) . ':' . sprintf("%'.02d", $diff->i) . ':' . sprintf("%'.02d", $diff->s);
            return $item;
        });
    }

    public function tradingHistoryServ(TradingHistoryRequest $request)
    {
        $admin = false;
        if ($request->id && Helper::isAdmin()) {
            $admin = true;
        }
        $id = $admin ? $request->id : Auth::user()->id;
        $history = DB::table('order_history_1')->where('user_id', $id)->get();
        return Datatables::of($history)->make(); // Вот тут
    }

    public function demoTradingHistoryServ(DemoTradingHistoryRequest $request)
    {
        $admin = false;
        if ($request->id && Helper::isAdmin()) {
            $admin = true;
        }
        $id = $admin ? $request->id : Auth::user()->id;
        $history = DB::table('order_demo_history_1')->where('user_id', $id)->get();
        return Datatables::of($history)->make();
    }

    public function refillDemoBalanceServ(Request $request)
    {
        User::where('id', Auth::user()->id)->update(['demo_balance' => 1000]);
        broadcast(new ChangeDemoBalance(1000, Auth::user()));
        return (['success' => true, 'message' => __('locale.trading_demo_balance_refresh')]);
    }

    public function ping(Request $request)
    {
        return null;
    }

    public function formatSecondsServ($seconds_from)
    {
        $hours = floor($seconds_from / 3600);
        $mins = floor($seconds_from / 60 % 60);
        $secs = floor($seconds_from % 60);
        return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
    }
}
