<?php

namespace App\Services\GateIO;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GateApiClient
{
    protected $baseUrl = 'https://api.gateio.ws/api/v4';
    protected $apiKey;
    protected $apiSecret;
    protected $client;

    public function __construct($apiKey = null, $apiSecret = null)
    {
        $this->apiKey = $apiKey ?? config('gateio.api_key');
        $this->apiSecret = $apiSecret ?? config('gateio.api_secret');
        $this->client = new Client(['base_uri' => $this->baseUrl]);
    }

    /**
     * 生成签名
     *
     * @param string $method HTTP方法
     * @param string $endpoint API端点
     * @param array $queryParams 查询参数
     * @param array|string $body 请求体
     * @return array 签名及相关头信息
     */
    protected function generateSignature($method, $endpoint, $queryParams = [], $body = '')
    {
        $timestamp = time();
        $hasParams = !empty($queryParams);
        $url = $endpoint . ($hasParams ? '?' . http_build_query($queryParams) : '');
        
        if (is_array($body)) {
            $body = json_encode($body);
        }
        
        $bodyHashed = hash('sha512', $body);
        $signString = "{$method}\n{$url}\n{$bodyHashed}\n{$timestamp}";
        $signature = hash_hmac('sha512', $signString, $this->apiSecret);
        
        return [
            'KEY' => $this->apiKey,
            'Timestamp' => $timestamp,
            'SIGN' => $signature,
        ];
    }

    /**
     * 发送API请求
     *
     * @param string $method HTTP方法
     * @param string $endpoint API端点
     * @param array $queryParams 查询参数
     * @param array $body 请求体
     * @param bool $auth 是否需要认证
     * @return mixed 响应数据
     * @throws \Exception
     */
    public function request($method, $endpoint, $queryParams = [], $body = [], $auth = false)
    {
        $options = [];
        
        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }
        
        if (!empty($body)) {
            $options['json'] = $body;
        }
        
        if ($auth) {
            $signature = $this->generateSignature($method, $endpoint, $queryParams, $body);
            $options['headers'] = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'KEY' => $signature['KEY'],
                'Timestamp' => $signature['Timestamp'],
                'SIGN' => $signature['SIGN'],
            ];
        } else {
            $options['headers'] = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];
        }
        
        try {
            $response = $this->client->request($method, $endpoint, $options);
            $contents = $response->getBody()->getContents();
            return json_decode($contents, true);
        } catch (GuzzleException $e) {
            throw new \Exception('API请求失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取市场K线图数据
     *
     * @param string $symbol 交易对
     * @param string $interval 时间间隔
     * @param int $limit 返回数量
     * @return array K线数据
     */
    public function getKlines($symbol, $interval = '1d', $limit = 100)
    {
        $endpoint = '/spot/candlesticks';
        $params = [
            'currency_pair' => $symbol,
            'interval' => $interval,
            'limit' => $limit
        ];
        
        return $this->request('GET', $endpoint, $params);
    }
    
    /**
     * 获取账户余额
     *
     * @return array 账户余额信息
     */
    public function getAccountBalance()
    {
        $endpoint = '/spot/accounts';
        return $this->request('GET', $endpoint, [], [], true);
    }
    
    /**
     * 创建订单
     *
     * @param string $symbol 交易对
     * @param string $side 买卖方向 (buy/sell)
     * @param string $type 订单类型 (limit/market)
     * @param float $amount 数量
     * @param float|null $price 价格 (limit订单需要)
     * @return array 订单信息
     */
    public function createOrder($symbol, $side, $type, $amount, $price = null)
    {
        $endpoint = '/spot/orders';
        $order = [
            'currency_pair' => $symbol,
            'side' => $side,
            'type' => $type,
            'amount' => (string)$amount,
        ];
        
        if ($price !== null) {
            $order['price'] = (string)$price;
        }
        
        return $this->request('POST', $endpoint, [], $order, true);
    }
    
    /**
     * 查询订单
     *
     * @param string $orderId 订单ID
     * @param string $symbol 交易对
     * @return array 订单信息
     */
    public function getOrder($orderId, $symbol)
    {
        $endpoint = "/spot/orders/{$orderId}";
        $params = ['currency_pair' => $symbol];
        
        return $this->request('GET', $endpoint, $params, [], true);
    }
    
    /**
     * 取消订单
     *
     * @param string $orderId 订单ID
     * @param string $symbol 交易对
     * @return array 订单信息
     */
    public function cancelOrder($orderId, $symbol)
    {
        $endpoint = "/spot/orders/{$orderId}";
        $params = ['currency_pair' => $symbol];
        
        return $this->request('DELETE', $endpoint, $params, [], true);
    }
} 