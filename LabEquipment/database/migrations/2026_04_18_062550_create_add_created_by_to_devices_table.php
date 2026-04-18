<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // 先定义字段并添加注释
            $table->unsignedBigInteger('created_by')
                ->nullable()
                ->after('status')
                ->comment('创建者ID');

            // 再添加外键约束
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 在闭包外部检查字段是否存在
        if (Schema::hasColumn('devices', 'created_by')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            });
        }
    }
};
