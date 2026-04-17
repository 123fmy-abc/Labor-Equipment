<?php

namespace app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Booking;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'description',
        'total_qty',
        'status',
    ];

    protected $appends = ['available_qty'];  // 自动添加到 JSON

    public function getAvailableQtyAttribute(): int
    {
        return $this->total_qty - Booking::where('device_id', $this->id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();
    }
     //关联分类表
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
