<?php

namespace App\Services\GateIO;

class TechnicalAnalysisService
{
    /**
     * 计算简单移动平均线 (SMA)
     *
     * @param array $closePrices 收盘价数组
     * @param int $period 周期
     * @return array SMA值
     */
    public function calculateSMA($closePrices, $period)
    {
        $result = [];
        $count = count($closePrices);
        
        for ($i = $period - 1; $i < $count; $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $closePrices[$i - $j];
            }
            $result[] = $sum / $period;
        }
        
        return $result;
    }
    
    /**
     * 计算指数移动平均线 (EMA)
     *
     * @param array $closePrices 收盘价数组
     * @param int $period 周期
     * @return array EMA值
     */
    public function calculateEMA($closePrices, $period)
    {
        $result = [];
        $count = count($closePrices);
        $multiplier = 2 / ($period + 1);
        
        // 第一个EMA值使用SMA
        $sma = array_sum(array_slice($closePrices, 0, $period)) / $period;
        $result[] = $sma;
        
        for ($i = $period; $i < $count; $i++) {
            $ema = ($closePrices[$i] - $result[count($result) - 1]) * $multiplier + $result[count($result) - 1];
            $result[] = $ema;
        }
        
        return $result;
    }
    
    /**
     * 计算相对强弱指标 (RSI)
     *
     * @param array $closePrices 收盘价数组
     * @param int $period 周期
     * @return array RSI值
     */
    public function calculateRSI($closePrices, $period)
    {
        $result = [];
        $count = count($closePrices);
        $gains = [];
        $losses = [];
        
        // 计算价格变化
        for ($i = 1; $i < $count; $i++) {
            $change = $closePrices[$i] - $closePrices[$i - 1];
            $gains[] = max(0, $change);
            $losses[] = max(0, -$change);
        }
        
        // 计算平均收益和平均损失
        for ($i = $period; $i <= count($gains); $i++) {
            $avgGain = array_sum(array_slice($gains, $i - $period, $period)) / $period;
            $avgLoss = array_sum(array_slice($losses, $i - $period, $period)) / $period;
            
            if ($avgLoss == 0) {
                $result[] = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $result[] = 100 - (100 / (1 + $rs));
            }
        }
        
        return $result;
    }
    
    /**
     * 计算MACD (移动平均线收敛/发散)
     *
     * @param array $closePrices 收盘价数组
     * @param int $fastPeriod 快线周期
     * @param int $slowPeriod 慢线周期
     * @param int $signalPeriod 信号线周期
     * @return array MACD值 [MACD线, 信号线, 直方图]
     */
    public function calculateMACD($closePrices, $fastPeriod = 12, $slowPeriod = 26, $signalPeriod = 9)
    {
        $fastEMA = $this->calculateEMA($closePrices, $fastPeriod);
        $slowEMA = $this->calculateEMA($closePrices, $slowPeriod);
        
        // 调整两个数组的长度使其一致
        $diff = count($closePrices) - count($slowEMA);
        $fastEMA = array_slice($fastEMA, $diff);
        
        // 计算MACD线
        $macdLine = [];
        for ($i = 0; $i < count($slowEMA); $i++) {
            $macdLine[] = $fastEMA[$i] - $slowEMA[$i];
        }
        
        // 计算信号线
        $signalLine = $this->calculateEMA($macdLine, $signalPeriod);
        
        // 计算直方图
        $histogram = [];
        $diff = count($macdLine) - count($signalLine);
        $macdLineAdjusted = array_slice($macdLine, $diff);
        
        for ($i = 0; $i < count($signalLine); $i++) {
            $histogram[] = $macdLineAdjusted[$i] - $signalLine[$i];
        }
        
        return [
            'macd' => $macdLine,
            'signal' => $signalLine,
            'histogram' => $histogram
        ];
    }
    
    /**
     * 处理K线数据为分析所需格式
     *
     * @param array $klineData Gate.io K线数据
     * @return array 处理后的数据 [时间戳, 开盘价, 最高价, 最低价, 收盘价, 成交量]
     */
    public function processKlineData($klineData)
    {
        $result = [
            'timestamps' => [],
            'open' => [],
            'high' => [],
            'low' => [],
            'close' => [],
            'volume' => [],
        ];
        
        foreach ($klineData as $kline) {
            $result['timestamps'][] = $kline[0]; // 时间戳
            $result['open'][] = (float)$kline[1]; // 开盘价
            $result['high'][] = (float)$kline[2]; // 最高价
            $result['low'][] = (float)$kline[3]; // 最低价
            $result['close'][] = (float)$kline[4]; // 收盘价
            $result['volume'][] = (float)$kline[5]; // 成交量
        }
        
        return $result;
    }
    
    /**
     * 生成交易信号
     *
     * @param array $klineData 处理后的K线数据
     * @return string 交易信号 (BUY/SELL/HOLD)
     */
    public function generateSignal($klineData)
    {
        $closePrices = $klineData['close'];
        
        // 计算技术指标
        $sma5 = $this->calculateSMA($closePrices, 5);
        $sma20 = $this->calculateSMA($closePrices, 20);
        $rsi = $this->calculateRSI($closePrices, 14);
        $macd = $this->calculateMACD($closePrices);
        
        // 获取最新的指标值
        $lastSMA5 = end($sma5);
        $lastSMA20 = end($sma20);
        $prevSMA5 = prev($sma5);
        $prevSMA20 = prev($sma20);
        $lastRSI = end($rsi);
        $lastMACDHist = end($macd['histogram']);
        $prevMACDHist = prev($macd['histogram']);
        
        // 重置数组指针
        reset($sma5);
        reset($sma20);
        reset($macd['histogram']);
        
        // 交易信号逻辑
        $signal = 'HOLD';
        
        // SMA交叉信号
        $crossAbove = ($prevSMA5 <= $prevSMA20) && ($lastSMA5 > $lastSMA20);
        $crossBelow = ($prevSMA5 >= $prevSMA20) && ($lastSMA5 < $lastSMA20);
        
        // RSI超买超卖信号
        $rsiOverbought = $lastRSI > 70;
        $rsiOversold = $lastRSI < 30;
        
        // MACD信号
        $macdPositiveCross = ($prevMACDHist <= 0) && ($lastMACDHist > 0);
        $macdNegativeCross = ($prevMACDHist >= 0) && ($lastMACDHist < 0);
        
        // 综合信号
        if (($crossAbove || $rsiOversold || $macdPositiveCross) && !$rsiOverbought) {
            $signal = 'BUY';
        } elseif (($crossBelow || $rsiOverbought || $macdNegativeCross) && !$rsiOversold) {
            $signal = 'SELL';
        }
        
        return $signal;
    }
} 