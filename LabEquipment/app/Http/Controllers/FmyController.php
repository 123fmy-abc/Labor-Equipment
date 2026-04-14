<?php

namespace app\Http\Controllers;

use app\Models\Booking;
use app\Models\Device;
use http\Env\Request;

class FmyController extends Controller
{
    //获取当前登录用户的借用记录
    public function myBookings(Request $request)
    {
        $userId = auth()->id();

        // 支持按状态筛选
        $status = $request->input('status');

        $query = Booking::where('user_id', $userId)
            ->with(['device' => function ($query) {
                $query->select('id', 'name', 'category_id')
                    ->with('category:id,name');
            }])
            ->orderBy('created_at', 'desc');

        // 如果指定了状态，进行筛选
        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'returned'])) {
            $query->where('status', $status);
        }

        // 支持分页，默认每页10条
        $perPage = $request->input('per_page', 10);
        $bookings = $query->paginate($perPage);

        // 格式化返回数据
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
                'start_date' => $booking->start_date->format('Y-m-d'),
                'end_date' => $booking->end_date->format('Y-m-d'),
                'purpose' => $booking->purpose,
                'status' => $booking->status,
                'status_text' => $this->getStatusText($booking->status),
                'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
                'approved_at' => $booking->approved_at ? $booking->approved_at->format('Y-m-d H:i:s') : null,
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
}
