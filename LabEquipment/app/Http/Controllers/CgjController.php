<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Device;
use App\Models\Category;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CgjController extends Controller
{
    // ==================== 1. 用户登录/登出模块 ====================

    /**
     * 用户登录
     * POST /auth/login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only('account', 'password');
        $field = filter_var($credentials['account'], FILTER_VALIDATE_EMAIL) ? 'email' : 'account';

        $token = Auth::guard('api')->attempt([
            $field => $credentials['account'],
            'password' => $credentials['password']
        ]);

        if (!$token) {
            return response()->json([
                'code' => 401,
                'message' => '账号或密码错误'
            ], 401);
        }

        $user = Auth::guard('api')->user();

        return response()->json([
            'code' => 200,
            'message' => '登录成功',
            'data' => [
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'account' => $user->account,
                    'email' => $user->email,
                    'role' => $user->role
                ]
            ]
        ]);
    }

    /**
     * 用户登出
     * POST /auth/logout
     */
    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json([
            'code' => 200,
            'message' => '退出成功'
        ]);
    }

    /**
     * 用户注册（如果需要）
     * POST /auth/register
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account' => 'required|string|unique:users',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'account' => $request->account,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'code' => 200,
            'message' => '注册成功',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ]);
    }


    // ==================== 2. 设备模块 - 最后4个接口 ====================

    /**
     * 修改设备状态
     * PUT /devices/{id}/status
     */
    public function updateDeviceStatus(Request $request, $id)
    {
        $device = Device::find($id);

        if (!$device) {
            return response()->json([
                'code' => 404,
                'message' => '设备不存在'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:available,maintenance,disabled'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $device->status = $request->status;
        $device->save();

        return response()->json([
            'code' => 200,
            'message' => '状态更新成功',
            'data' => $device
        ]);
    }

    /**
     * 删除设备
     * DELETE /devices/{id}
     */
    public function deleteDevice($id)
    {
        $device = Device::find($id);

        if (!$device) {
            return response()->json([
                'code' => 404,
                'message' => '设备不存在'
            ], 404);
        }

        // 检查是否有未完成的借用记录
        $hasActiveBookings = Booking::where('device_id', $id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($hasActiveBookings) {
            return response()->json([
                'code' => 400,
                'message' => '该设备有未完成的借用记录，无法删除'
            ], 400);
        }

        $device->delete();

        return response()->json([
            'code' => 200,
            'message' => '删除成功'
        ]);
    }

    /**
     * 设备使用统计
     * GET /devices/stats
     */
    public function deviceStats()
    {
        $totalDevices = Device::count();
        $availableDevices = Device::where('status', 'available')->count();
        $maintenanceDevices = Device::where('status', 'maintenance')->count();
        $disabledDevices = Device::where('status', 'disabled')->count();

        // 借用统计
        $totalBookings = Booking::count();
        $pendingBookings = Booking::where('status', 'pending')->count();
        $approvedBookings = Booking::where('status', 'approved')->count();
        $returnedBookings = Booking::where('status', 'returned')->count();
        $rejectedBookings = Booking::where('status', 'rejected')->count();

        // 最热门的设备
        $topDevices = Booking::select('device_id', DB::raw('count(*) as booking_count'))
            ->with('device')
            ->groupBy('device_id')
            ->orderBy('booking_count', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => [
                'devices' => [
                    'total' => $totalDevices,
                    'available' => $availableDevices,
                    'maintenance' => $maintenanceDevices,
                    'disabled' => $disabledDevices
                ],
                'bookings' => [
                    'total' => $totalBookings,
                    'pending' => $pendingBookings,
                    'approved' => $approvedBookings,
                    'returned' => $returnedBookings,
                    'rejected' => $rejectedBookings
                ],
                'top_devices' => $topDevices
            ]
        ]);
    }


    // ==================== 3. 分类模块 - 所有接口 ====================

    /**
     * 获取全部分类
     * GET /categories
     */
    public function getCategories()
    {
        $categories = Category::withCount('devices')->get();

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => $categories
        ]);
    }

    /**
     * 获取单个分类
     * GET /categories/{id}
     */
    public function getCategory($id)
    {
        $category = Category::with('devices')->find($id);

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => $category
        ]);
    }

    /**
     * 新增分类
     * POST /categories
     */
    public function createCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:125|unique:categories'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $category = Category::create($request->only('name'));

        return response()->json([
            'code' => 200,
            'message' => '添加成功',
            'data' => $category
        ]);
    }

    /**
     * 修改分类
     * PUT /categories/{id}
     */
    public function updateCategory(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:125|unique:categories,name,' . $id
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $category->name = $request->name;
        $category->save();

        return response()->json([
            'code' => 200,
            'message' => '修改成功',
            'data' => $category
        ]);
    }

    /**
     * 删除分类
     * DELETE /categories/{id}
     */
    public function deleteCategory($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        // 检查是否有设备关联
        $hasDevices = Device::where('category_id', $id)->exists();

        if ($hasDevices) {
            return response()->json([
                'code' => 400,
                'message' => '该分类下存在设备，无法删除'
            ], 400);
        }

        $category->delete();

        return response()->json([
            'code' => 200,
            'message' => '删除成功'
        ]);
    }

    /**
     * 获取指定分类下的设备
     * GET /categories/{id}/devices
     */
    public function getCategoryDevices(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        $query = Device::where('category_id', $id);

        if ($request->has('keyword') && $request->keyword) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        $devices = $query->paginate($request->get('limit', 10));

        // 计算可借数量
        $devices->getCollection()->transform(function ($device) {
            $device->available_qty = $device->getAvailableQuantity();
            return $device;
        });

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => $devices
        ]);
    }


    // ==================== 4. 借用模块 - 第1个接口 ====================

    /**
     * 提交借用申请
     * POST /bookings
     */
    public function createBooking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|exists:devices,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'purpose' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        $device = Device::find($request->device_id);

        // 检查设备状态
        if ($device->status !== 'available') {
            return response()->json([
                'code' => 400,
                'message' => '该设备当前不可借用'
            ], 400);
        }

        // 检查库存
        $availableQty = $device->getAvailableQuantity();
        if ($availableQty <= 0) {
            return response()->json([
                'code' => 400,
                'message' => '该设备当前无可用库存，请选择其他时间或设备'
            ], 400);
        }

        // 检查同一用户是否已有未完成的借用申请（同一设备）
        $existingBooking = Booking::where('user_id', Auth::id())
            ->where('device_id', $request->device_id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existingBooking) {
            return response()->json([
                'code' => 400,
                'message' => '您已有该设备的未完成借用申请，请先归还'
            ], 400);
        }

        // 创建借用申请
        $booking = Booking::create([
            'user_id' => Auth::id(),
            'device_id' => $request->device_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'purpose' => $request->purpose,
            'status' => 'pending'
        ]);

        return response()->json([
            'code' => 200,
            'message' => '申请已提交，等待审核',
            'data' => $booking->load('device', 'user')
        ]);
    }
}
