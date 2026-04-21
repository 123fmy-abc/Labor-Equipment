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

    // ==================== 2. 设备模块  ====================

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

        // 权限检查：超级管理员可以修改所有，其他管理员只能修改自己创建的
        $currentUser = auth()->user();
        if (!$currentUser->isSuperAdmin() && $device->created_by !== $currentUser->id) {
            return response()->json([
                'code' => 403,
                'message' => '无权修改此设备，只能修改自己创建的设备'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id',
            'description' => 'nullable|string',
            'total_qty' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:available,maintenance,disabled'
        ], [
            'name.string' => '设备名称必须是字符串',
            'name.max' => '设备名称最多255个字符',
            'category_id.exists' => '所选分类不存在',
            'total_qty.integer' => '总库存必须是整数',
            'total_qty.min' => '总库存不能小于0',
            'status.in' => '设备状态必须是 available、maintenance 或 disabled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        // 检查是否有任何字段被修改
        $fieldsToUpdate = ['name', 'category_id', 'description', 'total_qty', 'status'];
        $hasChanges = false;
        
        foreach ($fieldsToUpdate as $field) {
            if ($request->has($field) && $request->$field != $device->$field) {
                $hasChanges = true;
                break;
            }
        }
        
        if (!$hasChanges) {
            return response()->json([
                'code' => 200,
                'message' => '无任何修改',
                'data' => $device
            ]);
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

        // 根据状态重新计算可用库存
        if ($device->status === 'available') {
            // 可用状态：总库存 - 已借出
            $device->available_qty = $device->total_qty - Booking::where('device_id', $device->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->count();
        } else {
            // 维护中或禁用状态：可用库存为0
            $device->available_qty = 0;
        }
        $device->save();

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

        // 权限检查：超级管理员可以删除所有，其他管理员只能删除自己创建的
        $currentUser = auth()->user();
        if (!$currentUser->isSuperAdmin() && $device->created_by !== $currentUser->id) {
            return response()->json([
                'code' => 403,
                'message' => '无权删除此设备，只能删除自己创建的设备'
            ], 403);
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

        // 记录删除的设备信息
        $deletedDeviceInfo = [
            'name' => $device->name,
            'total_qty' => $device->total_qty,
            'available_qty' => $device->available_qty
        ];

        $device->delete();

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => $deletedDeviceInfo
        ]);
    }

    // ==================== 3. 分类模块 - 所有接口 ====================

    /**
     * 获取全部分类
     * GET /categories
     */
    public function getCategories()
    {
        try {
            // 只获取分类名称列表
            $categories = Category::pluck('name');

            return response()->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取单个分类
     * GET /categories/{id}
     */
    public function getCategory($id)
    {
        $category = Category::with(['createdBy'])->find($id);

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        // 构建详细响应
        $responseData = [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'created_by' => $category->createdBy,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => $responseData
        ]);
    }

    /**
     * 新增分类
     * POST /categories
     */
    public function createCategory(Request $request)
    {
        try {
            // 验证输入
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:categories,name',
                'description' => 'required|string', // 描述必填
            ], [
                'name.required' => '分类名称不能为空',
                'name.max' => '分类名称最多255个字符',
                'name.unique' => '该分类名称已存在，请使用其他名称',
                'description.required' => '分类描述不能为空',
            ]);

            // 获取当前登录用户
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作'
                ], 401);
            }

            // 创建分类，记录创建者
            $category = Category::create([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'created_by' => $user->id,
            ]);

            return response()->json([
                'code' => 200,
                'message' => '创建成功',
                'data' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '创建失败：' . $e->getMessage()
            ], 500);
        }
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

        // 权限检查：超级管理员可以修改所有，其他管理员只能修改自己创建的
        $currentUser = auth()->user();
        if (!$currentUser->isSuperAdmin() && $category->created_by !== $currentUser->id) {
            return response()->json([
                'code' => 403,
                'message' => '无权修改此分类，只能修改自己创建的分类'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:125|unique:categories,name,' . $id,
            'description' => 'nullable|string',  // 添加 description 验证
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        // 只更新传入的字段
        if ($request->has('name')) {
            $category->name = $request->name;
        }
        if ($request->has('description')) {
            $category->description = $request->description;
        }
        $category->save();

        return response()->json([
            'code' => 200,
            'message' => '修改成功',
            'data' => $category
        ], 200);
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

        // 权限检查：超级管理员可以删除所有，其他管理员只能删除自己创建的
        $currentUser = auth()->user();
        if (!$currentUser->isSuperAdmin() && $category->created_by !== $currentUser->id) {
            return response()->json([
                'code' => 403,
                'message' => '无权删除此分类，只能删除自己创建的分类'
            ], 403);
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
     * GET /category-devices?name=分类名
     * 通过name参数传分类名查询
     */
    public function getCategoryDevices(Request $request)
    {
        // 必须传name参数
        if (!$request->has('name') || !$request->name) {
            return response()->json([
                'code' => 422,
                'message' => '请传入分类名称（name参数）'
            ], 422);
        }

        // 按分类名查询
        $category = Category::where('name', $request->name)->first();

        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在'
            ], 404);
        }

        $query = Device::where('category_id', $category->id);

        if ($request->has('keyword') && $request->keyword) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        $devices = $query->paginate($request->get('limit', 10));

        // 计算可借数量并简化返回格式
        $simplifiedDevices = $devices->getCollection()->map(function ($device) {
            return [
                'id' => $device->id,
                'name' => $device->name,
                'description' => $device->description,
                'total_qty' => $device->total_qty,
                'available_qty' => $device->getAvailableQuantity(),
                'status' => $device->status,
                'category_id' => $device->category_id,
                'created_at' => $device->created_at,
                'updated_at' => $device->updated_at,
            ];
        });

        return response()->json([
            'code' => 200,
            'message' => '获取成功',
            'data' => [
                'list' => $simplifiedDevices,
                'total' => $devices->total(),
                'current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(),
                'per_page' => $devices->perPage(),
            ]
        ]);
    }


}
