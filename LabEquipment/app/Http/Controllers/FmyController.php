<?php

namespace app\Http\Controllers;

use app\Models\Booking;
use http\Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FmyController extends Controller
{
    //获取当前登录用户的借用记录
    public function myBookings(Request $request)
    {
        try {
            $userId = auth()->id();
            $status = $request->input('status');

            // 关联预加载（解决 N+1 问题）
            // 注意：必须包含外键 category_id，否则关联会失败
            $query = Booking::where('user_id', $userId)
                ->with(['device' => function ($q) {
                    $q->select('id', 'name', 'category_id') // 确保选出外键
                    ->with(['category' => function ($subQ) {
                        $subQ->select('id', 'name');
                    }]);
                }])
                ->orderBy('created_at', 'desc');

            // 指定状态筛选
            if ($status && in_array($status, ['pending', 'approved', 'rejected', 'returned'])) {
                $query->where('status', $status);
            }

            $perPage = $request->input('per_page', 10);
            $bookings = $query->paginate($perPage);

            // 格式化数据（使用安全的 optional() 辅助函数防止报错）
            $formattedData = $bookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'device' => $booking->device ? [
                        'id' => $booking->device->id,
                        'name' => $booking->device->name,
                        'category' => $booking->device->category ? [
                            'id' => $booking->device->category->id,
                            'name' => $booking->device->category->name,
                        ] : null,
                    ] : null,
                    // 使用 optional 防止日期为空时报错
                    'start_date' => optional($booking->start_date)->format('Y-m-d'),
                    'end_date' => optional($booking->end_date)->format('Y-m-d'),
                    'purpose' => $booking->purpose,
                    'status' => $booking->status,
                    'rejected_reason' => $booking->rejected_reason,
                    'approved_at' => optional($booking->approved_at)->format('Y-m-d H:i:s'),
                    'returned_at' => optional($booking->returned_at)->format('Y-m-d H:i:s'),
                    'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'list' => $formattedData,
                    'pagination' => [
                        'current_page' => $bookings->currentPage(),
                        'last_page' => $bookings->lastPage(),
                        'per_page' => $bookings->perPage(),
                        'total' => $bookings->total(),
                    ],
                ],
            ]);
        }
        catch (Exception $e) {
            //写入错误日志
            Log::error('获取借用记录失败: ' . $e->getMessage());

            return response()->json([
                'code' => 500,
                'message' => '服务器内部错误，获取记录失败',
                'data' => null,
            ], 500);
        }
    }
}
