<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\Device;
use App\Models\User;
use http\Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\CreateBookingRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class FmyController extends Controller
{
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        //忘记密码
        $email = $request->validated()['email'];

        // 生成6位数字重置码
        $resetCode = rand(100000, 999999);

        // 存入缓存，有效期10分钟
        cache()->put('password_reset_' . $email, $resetCode, 600);

        // 发送邮件
        try {
            Mail::raw("您的密码重置验证码是：{$resetCode}，10分钟内有效，请勿泄露给他人。如非本人操作，请忽略此邮件。", function ($message) use ($email) {
                $message->to($email)
                    ->subject('实验室设备系统 - 密码重置');
            });

            return response()->json([
                'code' => 200,
                'message' => '重置验证码已发送至您的邮箱，请查收',
                'data' => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '邮件发送失败：' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    //重置密码
    public function resetPassword(ResetPasswordRequest $request)
    {
        $validated = $request->validated();

        // 1. 验证重置码
        $cacheCode = cache()->get('password_reset_' . $validated['email']);
        if (!$cacheCode || $cacheCode != $validated['code']) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期',
                'data' => []
            ], 400);
        }

        // 2. 查找用户
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return response()->json([
                'code' => 404,
                'message' => '用户不存在',
                'data' => []
            ], 404);
        }

        // 3. 更新密码
        $user->update([
            'password' => Hash::make($validated['password'])
        ]);

        // 4. 删除缓存的重置码
        cache()->forget('password_reset_' . $validated['email']);

        // 5. 返回成功响应
        return response()->json([
            'code' => 200,
            'message' => '密码重置成功，请使用新密码登录',
            'data' => []
        ]);
    }


    //1.提交借用申请
    public function createBooking(CreateBookingRequest $request)
    {
        $userId = auth()->id();

        $validated = $request->validated();
        $deviceId = $validated['device_id'];
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];
        $purpose = $validated['purpose'];

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
            'purpose' => $purpose,
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
        //修改借用申请时排除自己的逻辑
        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        $borrowedQty = $query->count();

        // 已借用数量小于设备总数，说明还有库存
        return $borrowedQty < $device->total_qty;
    }





    //2.取消当前用户指定待审核申请
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






    //3.修改当前登录用户的指定待审核申请信息
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
                'data' => null
            ], 404);
        }

        // 仅 pending 状态可修改
        if ($booking->status !== 'pending') {
            return response()->json([
                'code' => 403,
                'message' => '仅待审核状态的申请可修改',
                'data' => null
            ], 403);
        }

        $validated = $request->validated();

        // 如果修改了 device_id 或日期，需要重新检查时间冲突
        if (isset($validated['device_id']) || isset($validated['start_date']) || isset($validated['end_date'])) {//判断是否修改了关键字段
           //如果传了新设备ID就用新的，否则用原来的
            $deviceId = $validated['device_id'] ?? $booking->device_id;
            $startDate = $validated['start_date'] ?? $booking->start_date->format('Y-m-d');
            $endDate = $validated['end_date'] ?? $booking->end_date->format('Y-m-d');

            // 如果修改了设备，检查新设备的分类是否与原设备一致
            if (isset($validated['device_id']) && $deviceId != $booking->device_id) {
                $oldDevice = Device::with('category')->find($booking->device_id);
                $newDevice = Device::with('category')->find($deviceId);

                if ($oldDevice && $newDevice) {
                    $oldCategoryId = $oldDevice->category_id;
                    $newCategoryId = $newDevice->category_id;

                    if ($oldCategoryId !== $newCategoryId) {
                        return response()->json([
                            'code' => 400,
                            'message' => '新设备与原设备不属于同一分类，请选择同分类的设备',
                            'data' => [
                                'old_device' => [
                                    'id' => $oldDevice->id,
                                    'name' => $oldDevice->name,
                                    'category_id' => $oldCategoryId,
                                    'category_name' => $oldDevice->category->name ?? null,
                                ],
                                'new_device' => [
                                    'id' => $newDevice->id,
                                    'name' => $newDevice->name,
                                    'category_id' => $newCategoryId,
                                    'category_name' => $newDevice->category->name ?? null,
                                ],
                            ]
                        ], 400);
                    }
                }
            }

            if (!$this->isDeviceAvailable($deviceId, $startDate, $endDate, $id)) {
                return response()->json([
                    'code' => 409,
                    'message' => '该时间段内设备库存不足，请选择其他时间',
                    'data' => null
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
                'purpose'=> $booking->purpose,
                'status' => $booking->status,
            ],
        ]);
    }





    //4.获取当前登录用户的借用申请记录
    public function myBooking(Request $request)
    {
        try {
            $userId = auth()->id();

            // 获取状态筛选参数
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

            // 格式化数据（只返回指定字段）
            $formattedData = $bookings->map(function ($booking) {
                return [
                    'device_id' => $booking->device_id,
                    'start_date' => optional($booking->start_date)->format('Y-m-d'),
                    'end_date' => optional($booking->end_date)->format('Y-m-d'),
                    'purpose' => $booking->purpose,
                    'status' => $booking->status,
                    'rejected_reason'=> $booking->rejected_reason,
                    'approved_at'=> $booking->approved_at,
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









    //5.获取当前登录用户的单条借用申请详情
    public function singleBooking(int $id)
    {
        try {
            $userId = auth()->id();

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





    //6.当前登录用户归还指定设备
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
                'data' => null
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







    //7.删除已结束记录
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







    //8.获取借用记录（管理员）
    public function allBooking(Request $request)
    {

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







    //9.审核通过（管理员，支持批量）
    public function approve(Request $request)
    {
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







    //10.审核拒绝（管理员，支持批量）
    public function reject(Request $request)
    {
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
