<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InviteCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'role',
        'max_uses',
        'used_count',
        'expires_at',
        'created_by',
        'used_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * 创建者关联
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 检查邀请码是否有效
     */
    public function isValid(): bool
    {
        // 检查是否过期
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // 检查是否达到最大使用次数
        if ($this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * 使用邀请码
     */
    public function use(): void
    {
        $this->increment('used_count');
        $this->update(['used_at' => now()]);
    }

    /**
     * 生成随机邀请码
     */
    public static function generateCode(): string
    {
        $prefix = 'BOSS';
        $year = date('Y');
        $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        
        return "{$prefix}-{$year}-{$random}";
    }

    /**
     * 创建新的邀请码
     */
    public static function createCode(int $createdBy, string $role = 'admin', int $maxUses = 1, ?int $expireDays = null): self
    {
        $code = self::generateCode();
        
        // 确保唯一性
        while (self::where('code', $code)->exists()) {
            $code = self::generateCode();
        }

        return self::create([
            'code' => $code,
            'role' => $role,
            'max_uses' => $maxUses,
            'created_by' => $createdBy,
            'expires_at' => $expireDays ? now()->addDays($expireDays) : null,
        ]);
    }
}
