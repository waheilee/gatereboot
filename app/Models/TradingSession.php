<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradingSession extends Model
{
    use HasFactory;
    
    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'symbol',
        'initial_balance',
        'current_balance',
        'profit_amount',
        'profit_percentage',
        'profit_threshold',
        'loss_threshold',
        'status',
        'start_time',
        'end_time',
        'stop_reason',
    ];
    
    /**
     * 应该转换为日期的属性
     *
     * @var array
     */
    protected $dates = [
        'start_time',
        'end_time',
        'created_at',
        'updated_at',
    ];
    
    /**
     * 获取与本会话关联的交易记录
     */
    public function transactions()
    {
        return $this->hasMany(TradeTransaction::class, 'session_id');
    }
} 