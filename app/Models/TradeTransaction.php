<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradeTransaction extends Model
{
    use HasFactory;
    
    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'session_id',
        'order_id',
        'type',
        'price',
        'amount',
        'total',
        'profit_percentage',
        'close_reason',
        'status',
        'executed_at',
    ];
    
    /**
     * 应该转换为日期的属性
     *
     * @var array
     */
    protected $dates = [
        'executed_at',
        'created_at',
        'updated_at',
    ];
    
    /**
     * 获取关联的交易会话
     */
    public function session()
    {
        return $this->belongsTo(TradingSession::class, 'session_id');
    }
} 