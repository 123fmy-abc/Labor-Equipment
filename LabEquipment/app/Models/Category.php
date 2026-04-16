<?php

namespace app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $table = 'categories';
    protected $fillable = [
        'name',           // 分类名称
        'description',    // 分类描述
        'parent_id',      // 父分类ID（如果有多级分类）
        'sort',           // 排序
        'status',         // 状态
    ];
    public function devices()
    {
        return $this->hasMany(Device::class,'category_id');
    }


    // 如果分类有多级结构
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
