<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\Device;
use http\Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\CreateBookingRequest;

class FmyController extends Controller
{

    //提交借用申请
    public function createBooking(CreateBookingRequest $request)
    {
        $userId = auth()->id();

        $validated = $request->validated();
        $deviceId = $validated['device_id'];
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        // 检查设备是否存在且可用
        $device = Device::where('id', $deviceId)
            ->where('status', 'available')
            ->first();

        if (!$device) {
            return response()->json([
                'code' => 404,
                'message' => '设备不存在或当前不可借用',
                'data' => null
            ], 404);
        }

        // 检查该时间段内设备库存是否充足
        if (!$this->isDeviceAvailable($deviceId, $startDate, $endDate)) {
            return response()->json([
                'code' => 409,
                'message' => '该时间段内设备库存不足，请选择其他时间',
                'data' => null
            ], 409);
        }

        // 创建借用申请
        $booking = Booking::create([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'purpose' => $validated['purpose'],
            'status' => 'pending',
        ]);

        // 加载关联数据
        $booking->load(['device' => function ($query) {
            $query->select('id', 'name', 'category_id')
                ->with('category:id,name');
        }]);

        return response()->json([
            'code' => 201,
            'message' => '申请提交成功，等待管理员审核',
            'data' => [
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
                'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }




    /**
     * 检查设备在指定时间段内是否可用（库存充足）
     */
    private function isDeviceAvailable(int $deviceId, string $startDate, string $endDate, ?int $excludeBookingId = null): bool
    {
        $device = Device::find($deviceId);

        if (!$device) {
            return false;
        }

        // 查询该设备在指定时间段内已批准的借用数量
        $query = Booking::where('device_id', $deviceId)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                // 日期范围重叠的条件：
                // 1. 新申请的开始日期在已有借用期间内
                // 2. 新申请的结束日期在已有借用期间内
                // 3. 新申请的日期范围完全包含已有借用期间
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($subQ) use ($startDate, $endDate) {
                        $subQ->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        // 如果是修改操作，排除当前记录
        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        $borrowedQty = $query->count();

        // 已借用数量小于设备总数，说明还有库存
        return $borrowedQty < $device->total_qty;
    }





    //取消当前用户指定待审核申请
    public function cancelMyPending(int $id)
    {
        $userId = auth()->id();

        // 查询当前用户的待审核申请
        $booking = Booking::where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 'pending')  // 确保是待审核状态
            ->first();

        // 记录不存在或不是待审核状态
        if (!$booking) {
            return response()->json([
                'code' => 404,
                'message' => '借用记录不存在、无权操作或不是待审核状态',
                'data' => null
            ], 404);
        }
        //直接删除记录
        $booking->delete();

        return response()->json([
            'code' => 200,
            'message' => '取消成功',
            'data' =>null
        ]);
    }






    //修改当前登录用户的指定待审核申请信息
    public function changeBooking($id, UpdateBookingRequest $request)
    {
        $userId = auth()->id();

        $booking = Booking::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$booking) {
            return response()->json([
                'code' => 404,
                'message' => '借用记录不存在或无权修改',
            ], 404);
        }

        // 仅 pending 状态可修改
        if ($booking->status !== 'pending') {
            return response()->json([
                'code' => 403,
                'message' => '仅待审核状态的申请可修改',
            ], 403);
        }

        $validated = $request->validated();

        // 如果修改了 device_id 或日期，需要重新检查时间冲突
        if (isset($validated['device_id']) || isset($validated['start_date']) || isset($validated['end_date'])) {
            $deviceId = $validated['device_id'] ?? $booking->device_id;
            $startDate = $validated['start_date'] ?? $booking->start_date->format('Y-m-d');
            $endDate = $validated['end_date'] ?? $booking->end_date->format('Y-m-d');

            if (!$this->isDeviceAvailable($deviceId, $startDate, $endDate, $id)) {
                return response()->json([
                    'code' => 409,
                    'message' => '该时间段内设备库存不足，请选择其他时间',
                ], 409);
            }
        }

        // 更新记录（只更新传入的字段）
        $booking->update($validated);

        // 重新加载关联数据
        $booking->load(['device' => function ($query) {
            $query->select('id', 'name', 'category_id')
                ->with('category:id,name');
        }]);

        return response()->json([
            'code' => 200,
            'message' => '修改成功',
            'data' => [
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
                'status' => $booking->status,
                'updated_at' => $booking->updated_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }





    //获取当前登录用户的所有借用申请记录
    public function myBooking(Request $request)
    {
        try {
            $userId = auth()->id();
            // 用户未登录
            if (!$userId) {
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作',
                    'data' => null
                ], 401);
            }

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









    //获取当前登录用户的单条借用申请详情
    public function singleBooking(int $id)
    {
        try {
            $userId = auth()->id();

            // 用户未登录
            if (!$userId) {
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作',
                    'data' => null
                ], 401);
            }

            // 查询当前用户的单条借用记录
            $booking = Booking::where('id', $id)
                ->where('user_id', $userId)
                ->with(['device' => function ($query) {
                    $query->select('id', 'name', 'description', 'category_id', 'total_qty', 'status')
                        ->with('category:id,name');
                }])
                ->first();

            // 记录不存在 / 无权限（核心失败）
            if (!$booking) {
                return response()->json([
                    'code' => 404,
                    'message' => '借用记录不存在或无权查看',
                    'data' => null
                ], 404);
            }

            // 格式化返回数据
            $data = [
                'id' => $booking->id,
                'device' => $booking->device ? [
                    'id' => $booking->device->id,
                    'name' => $booking->device->name,
                    'description' => $booking->device->description,
                    'category' => $booking->device->category ? [
                        'id' => $booking->device->category->id,
                        'name' => $booking->device->category->name,
                    ] : null,
                    'total_qty' => $booking->device->total_qty,
                    'status' => $booking->device->status,
                ] : null,
                'start_date' => $booking->start_date?->format('Y-m-d'),
                'end_date' => $booking->end_date?->format('Y-m-d'),
                'purpose' => $booking->purpose,
                'status' => $booking->status,
                'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $booking->updated_at->format('Y-m-d H:i:s'),
                'approved_at' => $booking->approved_at?->format('Y-m-d H:i:s') ?? null,
            ];

            // 成功响应
            return response()->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            // 服务器异常 / 数据库错误（兜底失败）
            return response()->json([
                'code' => 500,
                'message' => '获取借用记录失败，服务器异常',
                'data' => null
            ], 500);
        }
    }





    //当前登录用户归还指定设备
    public function returnBooking(int $id)
    {
        $userId = auth()->id();

        // 查询当前用户的已批准申请
        $booking = Booking::where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 'approved')  // 仅 approved 可归还
            ->with('device')
            ->first();
        // 记录不存在或不是 approved 状态
        if (!$booking) {
            return response()->json([
                'code' => 404,
                'message' => '借用记录不存在、无权操作或不是已批准状态',
            ], 404);
        }
        // 更新申请状态为已归还
        $booking->update([
            'status' => 'returned',
            'returned_at' => now(),
        ]);
        $device = $booking->device;
        $device->increment('available_qty', 1);
        return response()->json([
            'code' => 200,
            'message' => '归还成功',
            'data' => [
                'booking_id' => $id,
                'device_name' => $booking->device->name ?? null,
                'status' => 'returned',
                'returned_at' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }







    //删除已结束记录
    public function deleteFinished(int $id)
    {
        $userId = auth()->id();

        // 查询当前用户的已结束申请
        $booking = Booking::where('id', $id)
            ->where('user_id', $userId)
            ->whereIn('status', ['rejected', 'returned'])  // 仅 rejected/returned 可删除
            ->first();

        // 记录不存在或不是已结束状态
        if (!$booking) {
            return response()->json([
                'code' => 404,
                'message' => '借用记录不存在、无权操作或不是已结束状态（仅已拒绝/已归还可删除）',
            ], 404);
        }
        // 删除记录
        $booking->delete();
        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null
        ]);
    }







    //获取借用记录（管理员）
    public function allBooking(Request $request)
    {
        // 添加管理员权限检查
         if (!auth()->user()->isAdmin()) {
             return response()->json([
                 'code' => 403,
                 'message' => '无权访问',
                 'data'=>null
             ], 403);
         }

        $query = Booking::with([
            'user:id,name,account',
            'device' => function ($q) {
                $q->select('id', 'name', 'category_id')
                    ->with('category:id,name');
            }
        ]);

        // 按用户筛选
        if ($request->has('user_id') && $request->input('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // 按状态筛选
        if ($request->has('status') && $request->input('status')) {
            $status = $request->input('status');
            if (in_array($status, ['pending', 'approved', 'rejected', 'returned'])) {
                $query->where('status', $status);
            }
        }

        // 关键字搜索（用户名、设备名、用途）
        if ($request->has('keyword') && $request->input('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('purpose', 'like', "%{$keyword}%")
                    ->orWhereHas('user', function ($subQ) use ($keyword) {
                        $subQ->where('name', 'like', "%{$keyword}%")
                            ->orWhere('account', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('device', function ($subQ) use ($keyword) {
                        $subQ->where('name', 'like', "%{$keyword}%");
                    });
            });
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        // 分页
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 10);
        $bookings = $query->paginate($limit, ['*'], 'page', $page);

        // 格式化数据
        $formattedData = $bookings->map(function ($booking) {
            return [
                'id' => $booking->id,
                'user' => $booking->user ? [
                    'id' => $booking->user->id,
                    'name' => $booking->user->name,
                    'account' => $booking->user->account,
                ] : null,
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
                'created_at' => $booking->created_at->format('Y-m-d H:i:s'),
                'approved_at' => $booking->approved_at ? $booking->approved_at->format('Y-m-d H:i:s') : null,
            ];
        });

        // 统计信息
        $statistics = [
            'total' => Booking::count(),
            'pending' => Booking::where('status', 'pending')->count(),
            'approved' => Booking::where('status', 'approved')->count(),
            'rejected' => Booking::where('status', 'rejected')->count(),
            'returned' => Booking::where('status', 'returned')->count(),
        ];

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
                'statistics' => $statistics,
            ],
        ]);
    }







    //审核通过（管理员，支持批量）
    public function approve(Request $request)
    {
        // 管理员权限检查
        if (!auth()->user()->isAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '无权访问',
                'data' => null
            ], 403);
        }

        // 验证参数
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:bookings,id',
        ], [
            'ids.required' => '请选择要审批的记录',
            'ids.array' => '参数格式错误，ids必须是数组',
            'ids.min' => '请至少选择一条记录',
            'ids.*.integer' => '记录ID必须是整数',
            'ids.*.exists' => '记录不存在',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '参数错误',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = $request->input('ids');

        // 查询所有待审核的申请
        $bookings = Booking::whereIn('id', $ids)
            ->where('status', 'pending')
            ->with('device')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'code' => 404,
                'message' => '没有符合条件的待审核记录',
                'data' => null
            ], 404);
        }

        // 检查库存是否充足
        $insufficientDevices = [];
        foreach ($bookings as $booking) {
            $device = $booking->device;
            if ($device->available_qty <= 0) {
                $insufficientDevices[] = $device->name;
            }
        }

        if (!empty($insufficientDevices)) {
            return response()->json([
                'code' => 409,
                'message' => '以下设备库存不足：' . implode('、', $insufficientDevices),
                'data' => [
                    'insufficient_devices' => $insufficientDevices,
                ]
            ], 409);
        }

        // 开启事务
        DB::beginTransaction();
        try {
            $approvedCount = 0;
            $approvedIds = [];

            foreach ($bookings as $booking) {
                $device = $booking->device;
                
                // 减少设备可用库存
                $device->decrement('available_qty', 1);

                // 更新申请状态为已通过
                $booking->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                ]);

                $approvedCount++;
                $approvedIds[] = $booking->id;
            }

            DB::commit();

            return response()->json([
                'code' => 200,
                'message' => "审批通过 {$approvedCount} 条记录",
                'data' => [
                    'approved_ids' => $approvedIds,
                    'approved_count' => $approvedCount,
                    'total_count' => count($ids),
                    'approved_at' => now()->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'message' => '审批失败：' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }







    //审核拒绝（管理员，支持批量）
    public function reject(Request $request)
    {
        // 管理员权限检查
        if (!auth()->user()->isAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '无权访问',
                'data' => null
            ], 403);
        }

        // 验证参数
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:bookings,id',
            'reason' => 'required|string|min:2|max:500',
        ], [
            'ids.required' => '请选择要拒绝的记录',
            'ids.array' => '参数格式错误，ids必须是数组',
            'ids.min' => '请至少选择一条记录',
            'ids.*.integer' => '记录ID必须是整数',
            'ids.*.exists' => '记录不存在',
            'reason.required' => '拒绝原因必须填写',
            'reason.min' => '拒绝原因至少2个字符',
            'reason.max' => '拒绝原因不能超过500个字符',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '参数错误',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = $request->input('ids');
        $reason = $request->input('reason');

        // 查询所有待审核的申请
        $bookings = Booking::whereIn('id', $ids)
            ->where('status', 'pending')
            ->with('device')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'code' => 404,
                'message' => '没有符合条件的待审核记录',
                'data' => null
            ], 404);
        }

        // 批量更新为已拒绝
        $rejectedCount = 0;
        $rejectedIds = [];

        foreach ($bookings as $booking) {
            $booking->update([
                'status' => 'rejected',
                'rejected_reason' => $reason,
                'approved_at' => now(),
            ]);

            $rejectedCount++;
            $rejectedIds[] = $booking->id;
        }

        return response()->json([
            'code' => 200,
            'message' => "审批拒绝 {$rejectedCount} 条记录",
            'data' => [
                'rejected_ids' => $rejectedIds,
                'rejected_count' => $rejectedCount,
                'total_count' => count($ids),
                'rejected_reason' => $reason,
                'rejected_at' => now()->format('Y-m-d H:i:s'),
            ]
        ]);
    }

}
