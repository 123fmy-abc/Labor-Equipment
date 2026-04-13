<?php

namespace app\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use app\Models\Booking;
use app\Models\Device;
use http\Env\Request;

class FmyController extends Controller
{
    //提交借用申请
    public function StoreBooking(StoreBookingRequest $request)
    {
        $validator = $request->validated();

        // 检查设备是否可借用
        $device = Device::with('category')->find($validator['device_id']);
        if (!$device || $device->status !== 'available') {
            return response()->json([
                'code' => 400,
                'message' => '该设备当前不可借用',
                'data'=>null
            ], 400);
        }

        // 检查设备是否已被预约（时间冲突检查）
        $conflictBooking = Booking::where('device_id', $validator['device_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($validated) {
                $query->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhere(function ($q) use ($validated) {
                        $q->where('start_date', '<=', $validated['start_date'])
                            ->where('end_date', '>=', $validated['end_date']);
                    });
            })
            ->first();

        if ($conflictBooking) {
            return response()->json([
                'code' => 409,
                'message' => '该时间段内设备已被预约，请选择其他时间'
            ], 409);
        }

        // 创建借用申请
        $booking = Booking::create([
            'user_id' => auth()->id(), // 当前登录用户ID
            'device_id' => $validated['device_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'purpose' => $validated['purpose'],
            'status' => 'pending', // 默认待审核状态
        ]);

        return response()->json([
            'code' => 200,
            'message' => '借用申请提交成功',
            'data' => [
                'booking_id' => $booking->id,
                'device' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'category' => $device->category ? [
                        'id' => $device->category->id,
                        'name' => $device->category->name,
                    ] : null,
                ],
                'start_date' => $booking->start_date->format('Y-m-d'),
                'end_date' => $booking->end_date->format('Y-m-d'),
                'purpose' => $booking->purpose,
                'status' => $booking->status,
                'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }
}
