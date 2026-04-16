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

    // ==================== 2. 设备模块 - 最后4个接口 ====================

    /**
     * 修改设备信息
     * PUT /devices/{id}
     */
    public function updateDevice(Request $request,$id)
    {
        $device=Device::find($id);

        if(!$device){
            return response()->json([
                'code'=>404,
                'message'=>'设备不存在'
            ],404);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id',
            'description' => 'nullable|string',
            'total_qty' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:available,maintenance,disabled'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        // 如果要修改总库存，需要校验新库存不能小于已借出数量
        if ($request->has('total_qty')) {
            $borrowedCount = Booking::where('device_id', $id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            if ($request->total_qty < $borrowedCount) {
                return response()->json([
                    'code' => 400,
                    'message' => '总库存不能小于已借出数量（当前已借出：' . $borrowedCount . '）'
                ], 400);
            }
        }

        $device->update($request->only(['name', 'category_id', 'description', 'total_qty', 'status']));

        // 重新计算可借数量
        $device->available_qty = $device->total_qty - Booking::where('device_id', $device->id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();

        return response()->json([
            'code' => 200,
            'message' => '修改成功',
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


}
