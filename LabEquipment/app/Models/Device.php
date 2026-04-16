<?php

namespace app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    // 字段类型转换
    protected $casts = [
        'total_qty' => 'integer',
        'available_qty' => 'integer',
        'category_id' => 'integer',
        'deleted_at' => 'datetime',
    ];
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

}
