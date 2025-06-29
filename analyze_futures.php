<?php

// 載入 Composer 的自動載入器，這樣我們才能使用安裝的套件
require 'vendor/autoload.php';

// 使用我們安裝的幣安 API 客戶端
use GuzzleHttp\Client;
use MGG\BinanceFuture\BinanceFuture;

// =============================================================================
// 技術指標輔助函式 (從之前的回答複製過來)
// =============================================================================
function calculate_sma(array $data, int $period): array {
    $sma = [];
    $data_count = count($data);
    for ($i = 0; $i < $data_count; $i++) {
        if ($i < $period - 1) {
            $sma[] = null;
            continue;
        }
        $sum = 0;
        for ($j = 0; $j < $period; $j++) {
            $sum += $data[$i - $j];
        }
        $sma[] = $sum / $period;
    }
    return $sma;
}

function calculate_ema(array $data, int $period): array {
    $ema = [];
    $multiplier = 2 / ($period + 1);
    $previous_ema = null;
    foreach ($data as $key => $value) {
        if ($value === null) {
            $ema[] = null;
            continue;
        }
        if ($previous_ema === null) {
            $initial_data = array_slice($data, 0, $key + 1);
            $initial_sma_array = calculate_sma($initial_data, $period);
            $initial_sma = end($initial_sma_array);
            if ($initial_sma !== null) {
                $previous_ema = $initial_sma;
                $ema[] = $previous_ema;
            } else {
                $ema[] = null;
            }
        } else {
            $current_ema = (($value - $previous_ema) * $multiplier) + $previous_ema;
            $ema[] = $current_ema;
            $previous_ema = $current_ema;
        }
    }
    return $ema;
}


// =============================================================================
// DMA+九轉1 策略分析類別 (從之前的回答複製過來)
// =============================================================================
class DmaTdStrategy {
    // ... 將上一回答中的整個 DmaTdStrategy Class 內容完整複製到這裡 ...
    // ... 為了節省篇幅，此處省略，請務必複製過來 ...
    private array $candles;
    private array $params;
    public array $results = [];

    public function __construct(array $candles, array $params) {
        $this->candles = $candles;
        $this->params = array_merge([
            'dma_fast_len' => 5,
            'dma_slow_len' => 20,
            'dma_signal_len' => 9,
            'td_setup_len' => 4,
            'long_entry_td_num' => 1,
        ], $params);
    }
    
    public function calculate() {
        $closes = array_column($this->candles, 'close');
        $candle_count = count($this->candles);
        $fast_ma = calculate_ema($closes, $this->params['dma_fast_len']);
        $slow_ma = calculate_ema($closes, $this->params['dma_slow_len']);
        $dma_line = [];
        for ($i = 0; $i < $candle_count; $i++) {
            if ($fast_ma[$i] !== null && $slow_ma[$i] !== null) {
                $dma_line[] = $fast_ma[$i] - $slow_ma[$i];
            } else {
                $dma_line[] = null;
            }
        }
        $dma_signal = calculate_ema($dma_line, $this->params['dma_signal_len']);
        $buy_setup = 0;
        for ($i = 0; $i < $candle_count; $i++) {
            $result = [
                'timestamp' => $this->candles[$i]['timestamp'],
                'close' => $closes[$i],
                'dma_line' => $dma_line[$i],
                'dma_signal' => $dma_signal[$i],
                'is_dma_bullish_trend' => null,
                'buy_setup' => 0,
                'is_long_entry_signal' => false,
                'is_trigger' => false,
            ];
            $prev_buy_setup = $this->results[$i-1]['buy_setup'] ?? 0;
            if ($i >= $this->params['td_setup_len']) {
                if ($closes[$i] < $closes[$i - $this->params['td_setup_len']]) {
                    $result['buy_setup'] = min($prev_buy_setup + 1, 9);
                } else {
                    $result['buy_setup'] = 0;
                }
            } else {
                $result['buy_setup'] = 0;
            }
            if ($dma_line[$i] !== null && $dma_signal[$i] !== null) {
                $result['is_dma_bullish_trend'] = $dma_line[$i] > $dma_signal[$i];
                if ($result['buy_setup'] == $this->params['long_entry_td_num'] && $prev_buy_setup != $this->params['long_entry_td_num']) {
                    $result['is_long_entry_signal'] = true;
                }
            }
            if ($result['is_dma_bullish_trend'] && $result['is_long_entry_signal']) {
                $result['is_trigger'] = true;
            }
            $this->results[] = $result;
        }
    }
}


// =============================================================================
// 主執行程式
// =============================================================================

// 建立一個幣安 API 客戶端實例 (不需要 API Key 即可獲取公開市場數據)
$client = new Client();
$binance = new BinanceFuture($client);

// 1. 定義您想掃描的幣種列表和時間週期
$symbols_to_check = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'DOGEUSDT', 'NOTUSDT', 'PEPEUSDT'];
$interval = '4h'; // 4小時 K 線
$limit = 100; // 獲取最近 100 根 K 線

echo "開始掃描永續合約市場，時間週期: {$interval}...\n";
echo "================================================\n";

// 2. 循環遍歷所有要檢查的幣種
foreach ($symbols_to_check as $symbol) {
    try {
        echo "正在分析: {$symbol}... ";

        // 從幣安獲取 K 線數據
        $klines_raw = $binance->getKlines($symbol, $interval, ['limit' => $limit]);

        // 3. 格式化數據以符合策略類別的需求
        $candles = [];
        foreach ($klines_raw as $kline) {
            $candles[] = [
                'timestamp' => intval($kline[0] / 1000), // API 回傳的是毫秒，轉為秒
                'open' => floatval($kline[1]),
                'high' => floatval($kline[2]),
                'low' => floatval($kline[3]),
                'close' => floatval($kline[4]),
                'volume' => floatval($kline[5]),
            ];
        }

        // 4. 執行策略分析
        $strategy = new DmaTdStrategy($candles, []); // 使用預設參數
        $strategy->calculate();

        // 5. 獲取並檢查最新的分析結果
        $latest_result = end($strategy->results);

        if ($latest_result && $latest_result['is_trigger']) {
            echo "\n>>>>>> [觸發信號] <<<<<<\n";
            echo "幣種: {$symbol}\n";
            echo "時間: " . date('Y-m-d H:i:s T', $latest_result['timestamp']) . "\n";
            echo "價格: " . $latest_result['close'] . "\n";
            echo "---------------------------------\n";
        } else {
            echo "無信號。\n";
        }

    } catch (Exception $e) {
        echo "錯誤: 無法分析 {$symbol} - " . $e->getMessage() . "\n";
    }

    // 為了避免觸發幣安的 API 速率限制，每次請求後暫停一下
    sleep(1); 
}

echo "掃描完成。\n";