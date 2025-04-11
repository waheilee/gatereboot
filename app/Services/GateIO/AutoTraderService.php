<?php

namespace App\Services\GateIO;

use App\Models\TradeTransaction;
use App\Models\TradingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutoTraderService
{
    protected $apiClient;
    protected $technicalAnalysis;
    protected $tradingSession;
    protected $symbol;
    protected $profitThreshold;
    protected $lossThreshold;
    protected $initialBalance;
    protected $currentBalance;
    protected $isActive;
    protected $lastCheckTime;
    protected $position = null; // 持仓状态，null=未持仓，'long'=多头，'short'=空头
    protected $entryPrice = 0; // 入场价格
    
    /**
     * 构造函数
     *
     * @param GateApiClient $apiClient API客户端
     * @param TechnicalAnalysisService $technicalAnalysis 技术分析服务
     * @param string $symbol 交易对
     * @param float $profitThreshold 盈利阈值（百分比）
     * @param float $lossThreshold 亏损阈值（百分比）
     */
    public function __construct(
        GateApiClient $apiClient,
        TechnicalAnalysisService $technicalAnalysis,
        $symbol = 'BTC_USDT',
        $profitThreshold = 5.0,
        $lossThreshold = 2.0
    ) {
        $this->apiClient = $apiClient;
        $this->technicalAnalysis = $technicalAnalysis;
        $this->symbol = $symbol;
        $this->profitThreshold = $profitThreshold;
        $this->lossThreshold = $lossThreshold;
        $this->isActive = false;
        $this->lastCheckTime = now();
    }
    
    /**
     * 启动交易会话
     *
     * @return TradingSession 创建的交易会话
     */
    public function startSession()
    {
        // 获取账户余额
        $balanceData = $this->apiClient->getAccountBalance();
        
        // 解析获取USDT余额
        $this->initialBalance = $this->findCurrencyBalance($balanceData, 'USDT');
        $this->currentBalance = $this->initialBalance;
        
        // 创建新的交易会话
        $this->tradingSession = TradingSession::create([
            'symbol' => $this->symbol,
            'initial_balance' => $this->initialBalance,
            'current_balance' => $this->currentBalance,
            'profit_threshold' => $this->profitThreshold,
            'loss_threshold' => $this->lossThreshold,
            'status' => 'active',
            'start_time' => now(),
        ]);
        
        $this->isActive = true;
        
        Log::info("交易会话已启动: ID {$this->tradingSession->id}, 初始余额: {$this->initialBalance} USDT");
        
        return $this->tradingSession;
    }
    
    /**
     * 停止交易会话
     *
     * @param string $reason 停止原因
     * @return TradingSession 更新的交易会话
     */
    public function stopSession($reason = 'manual')
    {
        if (!$this->isActive) {
            return null;
        }
        
        // 如果有持仓，先平仓
        if ($this->position !== null) {
            $this->closePosition();
        }
        
        // 获取最新余额
        $balanceData = $this->apiClient->getAccountBalance();
        $this->currentBalance = $this->findCurrencyBalance($balanceData, 'USDT');
        
        // 计算盈亏
        $profit = $this->currentBalance - $this->initialBalance;
        $profitPercentage = ($profit / $this->initialBalance) * 100;
        
        // 更新交易会话
        $this->tradingSession->update([
            'current_balance' => $this->currentBalance,
            'profit_amount' => $profit,
            'profit_percentage' => $profitPercentage,
            'status' => 'closed',
            'end_time' => now(),
            'stop_reason' => $reason,
        ]);
        
        $this->isActive = false;
        
        Log::info("交易会话已停止: ID {$this->tradingSession->id}, 最终余额: {$this->currentBalance} USDT, 盈亏: {$profit} USDT ({$profitPercentage}%), 原因: {$reason}");
        
        return $this->tradingSession;
    }
    
    /**
     * 执行交易检查
     *
     * @return array 交易执行结果
     */
    public function executeTrading()
    {
        if (!$this->isActive) {
            return ['status' => 'error', 'message' => '交易会话未激活'];
        }
        
        // 获取K线数据
        $klineData = $this->apiClient->getKlines($this->symbol, '1d', 100);
        
        // 处理K线数据
        $processedData = $this->technicalAnalysis->processKlineData($klineData);
        
        // 生成交易信号
        $signal = $this->technicalAnalysis->generateSignal($processedData);
        
        // 获取当前价格
        $currentPrice = end($processedData['close']);
        
        // 检查是否需要平仓（达到盈利或亏损阈值）
        if ($this->position !== null) {
            $priceDiff = $this->position === 'long' 
                ? ($currentPrice - $this->entryPrice) / $this->entryPrice * 100
                : ($this->entryPrice - $currentPrice) / $this->entryPrice * 100;
                
            if ($priceDiff >= $this->profitThreshold) {
                $this->closePosition('profit_target');
                return ['status' => 'success', 'action' => 'close_position', 'reason' => '达到盈利目标'];
            } elseif ($priceDiff <= -$this->lossThreshold) {
                $this->closePosition('stop_loss');
                return ['status' => 'success', 'action' => 'close_position', 'reason' => '达到止损点'];
            }
        }
        
        // 根据信号执行交易
        if ($signal === 'BUY' && $this->position !== 'long') {
            if ($this->position === 'short') {
                $this->closePosition('signal_change');
            }
            $this->openPosition('long', $currentPrice);
            return ['status' => 'success', 'action' => 'buy', 'price' => $currentPrice];
        } elseif ($signal === 'SELL' && $this->position !== 'short') {
            if ($this->position === 'long') {
                $this->closePosition('signal_change');
            }
            $this->openPosition('short', $currentPrice);
            return ['status' => 'success', 'action' => 'sell', 'price' => $currentPrice];
        }
        
        return ['status' => 'success', 'action' => 'hold', 'message' => '保持当前仓位'];
    }
    
    /**
     * 检查是否需要在隔日重启
     *
     * @return bool 是否已重启
     */
    public function checkAndRestart()
    {
        $now = now();
        $lastCheckDate = Carbon::parse($this->lastCheckTime)->format('Y-m-d');
        $currentDate = $now->format('Y-m-d');
        
        // 如果日期变化，并且交易已停止
        if ($lastCheckDate !== $currentDate && !$this->isActive) {
            // 启动新的交易会话
            $this->startSession();
            $this->lastCheckTime = $now;
            
            Log::info("交易会话已在新的一天自动重启: ID {$this->tradingSession->id}");
            return true;
        }
        
        $this->lastCheckTime = $now;
        return false;
    }
    
    /**
     * 开仓
     *
     * @param string $positionType 仓位类型 (long/short)
     * @param float $price 开仓价格
     * @return bool 是否成功
     */
    protected function openPosition($positionType, $price)
    {
        // 获取账户余额
        $balanceData = $this->apiClient->getAccountBalance();
        $usdtBalance = $this->findCurrencyBalance($balanceData, 'USDT');
        
        // 计算购买数量（使用资金的90%）
        $amount = ($usdtBalance * 0.9) / $price;
        
        // 下单
        $side = $positionType === 'long' ? 'buy' : 'sell';
        $orderResult = $this->apiClient->createOrder(
            $this->symbol,
            $side,
            'limit',
            $amount,
            $price
        );
        
        if (isset($orderResult['id'])) {
            $this->position = $positionType;
            $this->entryPrice = $price;
            
            // 记录交易
            TradeTransaction::create([
                'session_id' => $this->tradingSession->id,
                'order_id' => $orderResult['id'],
                'type' => $side,
                'price' => $price,
                'amount' => $amount,
                'total' => $price * $amount,
                'status' => 'executed',
                'executed_at' => now(),
            ]);
            
            Log::info("开仓成功: {$positionType}, 价格: {$price}, 数量: {$amount}");
            return true;
        } else {
            Log::error("开仓失败: " . json_encode($orderResult));
            return false;
        }
    }
    
    /**
     * 平仓
     *
     * @param string $reason 平仓原因
     * @return bool 是否成功
     */
    protected function closePosition($reason = 'manual')
    {
        if ($this->position === null) {
            return false;
        }
        
        // 获取当前价格
        $klineData = $this->apiClient->getKlines($this->symbol, '1m', 1);
        $currentPrice = (float)$klineData[0][4]; // 最新收盘价
        
        // 下单
        $side = $this->position === 'long' ? 'sell' : 'buy';
        
        // 获取账户中的持仓量
        $symbol_parts = explode('_', $this->symbol);
        $baseCurrency = $symbol_parts[0];
        $balanceData = $this->apiClient->getAccountBalance();
        $baseAmount = $this->findCurrencyBalance($balanceData, $baseCurrency);
        
        $orderResult = $this->apiClient->createOrder(
            $this->symbol,
            $side,
            'limit',
            $baseAmount,
            $currentPrice
        );
        
        if (isset($orderResult['id'])) {
            // 计算盈亏
            $priceDiff = $this->position === 'long' 
                ? ($currentPrice - $this->entryPrice) / $this->entryPrice * 100
                : ($this->entryPrice - $currentPrice) / $this->entryPrice * 100;
            
            // 记录交易
            TradeTransaction::create([
                'session_id' => $this->tradingSession->id,
                'order_id' => $orderResult['id'],
                'type' => $side,
                'price' => $currentPrice,
                'amount' => $baseAmount,
                'total' => $currentPrice * $baseAmount,
                'profit_percentage' => $priceDiff,
                'close_reason' => $reason,
                'status' => 'executed',
                'executed_at' => now(),
            ]);
            
            // 更新余额
            $this->currentBalance = $this->findCurrencyBalance($balanceData, 'USDT');
            $this->tradingSession->update(['current_balance' => $this->currentBalance]);
            
            // 重置仓位
            $this->position = null;
            $this->entryPrice = 0;
            
            Log::info("平仓成功: 价格: {$currentPrice}, 数量: {$baseAmount}, 盈亏: {$priceDiff}%, 原因: {$reason}");
            
            // 检查是否达到阈值，需要停止会话
            $totalProfit = ($this->currentBalance - $this->initialBalance) / $this->initialBalance * 100;
            if ($totalProfit >= $this->profitThreshold || $totalProfit <= -$this->lossThreshold) {
                $this->stopSession($totalProfit >= $this->profitThreshold ? 'profit_target' : 'stop_loss');
            }
            
            return true;
        } else {
            Log::error("平仓失败: " . json_encode($orderResult));
            return false;
        }
    }
    
    /**
     * 从余额数据中查找特定币种的余额
     *
     * @param array $balanceData 余额数据
     * @param string $currency 币种
     * @return float 余额
     */
    protected function findCurrencyBalance($balanceData, $currency)
    {
        foreach ($balanceData as $balance) {
            if ($balance['currency'] === $currency) {
                return (float)$balance['available'];
            }
        }
        
        return 0;
    }
    
    /**
     * 获取当前交易会话
     *
     * @return TradingSession
     */
    public function getCurrentSession()
    {
        return $this->tradingSession;
    }
    
    /**
     * 检查交易会话是否活跃
     *
     * @return bool
     */
    public function isSessionActive()
    {
        return $this->isActive;
    }
} 