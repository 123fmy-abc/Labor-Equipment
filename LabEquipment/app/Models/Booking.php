<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    //给这个模型“安装”一个能力，让它能够方便地生成假数据（测试数据）
    use HasFactory;//命令行输入User::factory()->count(3)->create();即可生成假数据
    protected $fillable = [
        'user_id',
        'device_id',
        'quantity',
        'start_date',
        'end_date',
        'purpose',
        'status',
        'rejected_reason',
        'approved_at',
        'returned_at',
    ];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
