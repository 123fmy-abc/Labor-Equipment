<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('邀请码');
            $table->enum('role', ['admin', 'user'])->default('admin')->comment('该邀请码注册后的角色');
            $table->integer('max_uses')->default(1)->comment('最大使用次数');
            $table->integer('used_count')->default(0)->comment('已使用次数');
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->foreignId('created_by')->nullable()->constrained('users')->comment('创建者（超级管理员）');
            $table->timestamp('used_at')->nullable()->comment('最后使用时间');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_codes');
    }
};