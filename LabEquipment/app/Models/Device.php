<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Booking;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',           // 设备名称
        'category_id',    // 分类ID
        'description',    // 设备描述
        'total_qty',      // 总库存
        'available_qty',  // 可用库存
        'status',         // 状态：available, maintenance, disabled
        'created_by',     // 创建人ID
    ];

    // 字段类型转换
    protected $casts = [
        'total_qty' => 'integer',
        'available_qty' => 'integer',
        'category_id' => 'integer',
        'created_by' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * 获取创建者
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
     //关联分类表
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 获取该分类下的所有设备
     */
    public function devices()
    {
        return $this->hasMany(Device::class); // 或 Equipment::class，根据你的模型名称
    }

    /**
     * 获取可用数量
     * 可用数量 = 总库存 - 已借出数量
     */
    public function getAvailableQuantity(): int
    {
        $borrowedCount = Booking::where('device_id', $this->id)
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        return max(0, $this->total_qty - $borrowedCount);
    }

    /**
     * 获取已借出数量（实时统计）
     * 已借出 = pending + approved 状态的借用记录数
     */
    public function getBorrowedQtyAttribute(): int
    {
        return Booking::where('device_id', $this->id)
            ->whereIn('status', ['pending', 'approved'])
            ->count();
    }

    /**
     * 追加到序列化的属性
     */
    protected $appends = ['borrowed_qty'];

}
