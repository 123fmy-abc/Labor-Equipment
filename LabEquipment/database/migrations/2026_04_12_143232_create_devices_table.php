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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('设备名称，如：示波器');
            $table->foreignId('category_id')->constrained()->onDelete('restrict')->comment('分类，如：电子/光学');
            $table->text('description')->nullable()->comment('设备描述');
            $table->integer('borrow_qty')->default(0)->comment('每次借用数量，默认0');
            $table->integer('total_qty')->default(0)->comment('总库存');
            $table->integer('available_qty')->default(0)->comment('可用库存');
            $table->enum('status', ['available', 'maintenance', 'disabled'])
                ->default('available')->comment('状态：可借/维护中/下架');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
