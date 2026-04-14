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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // 关联用户
            // 加上 ->nullable()
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('set null'); // 关联设备
            $table->date('start_date')->comment('借用开始日期');
            $table->date('end_date')->comment('借用结束日期');
            $table->timestamp('returned_at')->nullable()->comment('实际归还时间');
            $table->text('purpose')->nullable()->comment('用途说明');
            $table->enum('status', ['pending', 'approved', 'rejected', 'returned'])
                ->default('pending')->comment('状态：待审核/已通过/已拒绝/已归还');
            $table->string('rejected_reason')->nullable()->comment('拒绝原因');
            $table->timestamp('approved_at')->nullable()->comment('审批时间');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
