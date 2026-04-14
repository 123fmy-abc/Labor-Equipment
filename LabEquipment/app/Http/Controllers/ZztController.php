<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ZztController extends Controller
{
    // -------------------------- 构造函数：统一中间件配置 --------------------------
    public function __construct()
    {
        // 1. 需要登录才能访问的接口（除了注册、登录、发验证码、验验证码、设备列表/可借/详情）
        $this->middleware('auth:api')->only([
            'logout', 'me', 'updateProfile', 'getDeviceList', 'addDevice'
        ]);
        // 2. 仅管理员可访问的接口（新增设备）
        $this->middleware('admin')->only(['addDevice']);
    }

    // ================================== 1. 用户注册（含管理员邀请码） ==================================
    /**
     * 接口功能：用户注册，支持管理员邀请码
     * 请求参数：account(学号/工号), name, email, password, password_confirmation, invite_code(可选)
     * 邀请码逻辑：输入正确的ADMIN_INVITE_CODE → 角色设为admin；否则默认user
     */
    public function register(Request $request)
    {
        // 1. 验证请求参数（规则：账号唯一、邮箱唯一、密码确认、邀请码可选）
        $validated = $request->validate([
            'account' => 'required|string|unique:users|max:20',
            'name' => 'required|string|max:50',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed', // password_confirmation 必须和password一致
            'invite_code' => 'nullable|string' // 管理员邀请码，可选填
        ]);

        // 2. 【核心】邀请码验证逻辑
        $role = 'user'; // 默认角色：普通用户
        // 从.env读取管理员邀请码，默认值ADMIN_SECRET_888
        $adminInviteCode = env('ADMIN_INVITE_CODE', 'ADMIN_SECRET_888');

        // 如果用户填写了邀请码，进行验证
        if ($request->filled('invite_code')) {
            if ($request->invite_code === $adminInviteCode) {
                // 邀请码正确 → 角色设为管理员
                $role = 'admin';
            } else {
                // 邀请码错误 → 仍允许注册，但角色保持user，返回提示
                return response()->json([
                    'code' => 200,
                    'message' => '邀请码错误，已注册为普通用户',
                    'data' => []
                ]);
            }
        }

        // 3. 创建用户（密码加密存储，绝对不能明文存！）
        $user = User::create([
            'account' => $validated['account'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']), // Hash加密
            'role' => $role
        ]);

        // 4. 生成JWT Token（登录态）
        $token = Auth::login($user);

        // 5. 返回成功响应（不返回密码，只返回必要信息）
        return response()->json([
            'code' => 200,
            'message' => '注册成功',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'account' => $user->account,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role
                ]
            ]
        ]);
    }

    // ================================== 2. 用户登录 ==================================
    /**
     * 接口功能：用户登录，返回Token
     * 请求参数：account(账号), password(密码)
     */
    public function login(Request $request)
    {
        // 1. 验证参数
        $validated = $request->validate([
            'account' => 'required|string',
            'password' => 'required|string'
        ]);

        // 2. 验证账号密码（JWT Auth的attempt方法）
        $credentials = $request->only('account', 'password');
        $token = Auth::attempt($credentials);

        // 3. 账号密码错误，抛出验证异常
        if (!$token) {
            throw ValidationException::withMessages([
                'account' => ['账号或密码错误'],
            ]);
        }

        // 4. 获取当前登录用户信息
        $user = Auth::user();

        // 5. 返回成功响应
        return response()->json([
            'code' => 200,
            'message' => '登录成功',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'account' => $user->account,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role
                ]
            ]
        ]);
    }

    // ================================== 3. 退出登录 ==================================
    /**
     * 接口功能：退出登录，销毁当前Token
     * 请求头：Authorization: Bearer {token}
     */
    public function logout()
    {
        // 销毁当前用户的Token
        Auth::logout();

        return response()->json([
            'code' => 200,
            'message' => '退出登录成功',
            'data' => []
        ]);
    }

    // ================================== 4. 获取当前登录用户信息 ==================================
    /**
     * 接口功能：获取当前登录用户的个人信息
     * 请求头：Authorization: Bearer {token}
     */
    public function me()
    {
        $user = Auth::user();

        return response()->json([
            'code' => 200,
            'message' => '获取用户信息成功',
            'data' => [
                'id' => $user->id,
                'account' => $user->account,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role
            ]
        ]);
    }

    // ================================== 5. 修改个人资料 ==================================
    /**
     * 接口功能：修改个人信息/密码
     * 请求头：Authorization: Bearer {token}
     * 请求参数：name(可选), email(可选), password(可选), password_confirmation(可选), old_password(改密码必填)
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        // 1. 验证参数（改密码时必须验证旧密码）
        $validated = $request->validate([
            'name' => 'nullable|string|max:50',
            'email' => 'nullable|email|unique:users,email,' . $user->id, // 排除当前用户的邮箱
            'password' => 'nullable|string|min:6|confirmed',
            'old_password' => 'required_if:password,!=null|string' // 改密码时，旧密码必填
        ]);

        // 2. 如果用户要修改密码，先验证旧密码是否正确
        if ($request->filled('password')) {
            if (!Hash::check($validated['old_password'], $user->password)) {
                return response()->json([
                    'code' => 400,
                    'message' => '旧密码错误',
                    'data' => []
                ]);
            }
            // 旧密码正确，加密新密码
            $user->password = Hash::make($validated['password']);
        }

        // 3. 更新姓名、邮箱（如果有传参）
        if ($request->filled('name')) {
            $user->name = $validated['name'];
        }
        if ($request->filled('email')) {
            $user->email = $validated['email'];
        }

        // 4. 保存修改
        $user->save();

        // 5. 返回更新后的用户信息
        return response()->json([
            'code' => 200,
            'message' => '修改资料成功',
            'data' => [
                'id' => $user->id,
                'account' => $user->account,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role
            ]
        ]);
    }

    // ================================== 6. 发送邮箱验证码 ==================================
    /**
     * 接口功能：发送6位数字邮箱验证码，有效期5分钟
     * 请求参数：email(邮箱)
     */
    public function sendEmailCode(Request $request)
    {
        // 1. 验证邮箱格式
        $request->validate([
            'email' => 'required|email'
        ]);

        // 2. 生成6位随机验证码
        $code = rand(100000, 999999);

        // 3. 把验证码存入缓存（key=email_code_邮箱，有效期300秒=5分钟）
        cache()->put('email_code_' . $request->email, $code, 300);

        // 4. 发送邮件（try-catch捕获异常，避免邮件服务崩溃导致接口报错）
        try {
            Mail::raw("您的实验室设备系统验证码是：{$code}，5分钟内有效，请勿泄露给他人。", function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('实验室设备系统 - 邮箱验证码');
            });

            return response()->json([
                'code' => 200,
                'message' => '验证码发送成功，请查收邮箱',
                'data' => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '验证码发送失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    // ================================== 7. 校验邮箱验证码 ==================================
    /**
     * 接口功能：校验用户输入的邮箱验证码是否正确
     * 请求参数：email(邮箱), code(验证码)
     */
    public function verifyEmailCode(Request $request)
    {
        // 1. 验证参数
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6' // 必须是6位
        ]);

        // 2. 从缓存中取出正确的验证码
        $cacheCode = cache()->get('email_code_' . $request->email);

        // 3. 验证码不存在（过期）或不匹配
        if (!$cacheCode || $cacheCode != $request->code) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期，请重新发送',
                'data' => []
            ]);
        }

        // 4. 验证成功，删除缓存（防止重复使用）
        cache()->forget('email_code_' . $request->email);

        return response()->json([
            'code' => 200,
            'message' => '验证码验证成功',
            'data' => []
        ]);
    }

    // ================================== 8. 获取设备列表（全部设备） ==================================
    /**
     * 接口功能：获取所有设备列表，支持关键词、分类筛选，分页
     * 请求参数：keyword(可选，关键词), category_id(可选，分类ID), page(可选，页码), limit(可选，每页数量)
     */
    public function getDeviceList(Request $request)
    {
        // 1. 获取请求参数，设置默认值
        $keyword = $request->input('keyword', '');
        $categoryId = $request->input('category_id', '');
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 10);

        // 2. 构建查询（关联分类表，方便前端显示分类名称）
        $query = Device::with('category');

        // 3. 关键词筛选（设备名称/描述模糊匹配）
        if ($keyword) {
            $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('description', 'like', "%{$keyword}%");
        }

        // 4. 分类筛选
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        // 5. 分页查询
        $devices = $query->paginate($limit, ['*'], 'page', $page);

        // 6. 给每个设备补充「可借数量」字段
        $devices->getCollection()->transform(function ($device) {
            $device->available_qty = $device->available_qty;
            return $device;
        });

        // 7. 返回响应
        return response()->json([
            'code' => 200,
            'message' => '获取设备列表成功',
            'data' => [
                'list' => $devices->items(),
                'total' => $devices->total(),
                'page' => $devices->currentPage(),
                'limit' => $devices->perPage()
            ]
        ]);
    }

    // ================================== 9. 获取可借设备列表 ==================================
    /**
     * 接口功能：获取当前可借的设备列表（状态正常+有库存），支持筛选、分页
     * 请求参数：keyword(可选), category_id(可选), page(可选), limit(可选)
     */
    public function getAvailableDeviceList(Request $request)
    {
        // 1. 获取请求参数
        $keyword = $request->input('keyword', '');
        $categoryId = $request->input('category_id', '');
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 10);

        // 2. 构建查询（只查状态为available，且可借数量>0的设备）
        $query = Device::with('category')
            ->where('status', 'available')
            ->having('available_qty', '>', 0);

        // 3. 关键词筛选
        if ($keyword) {
            $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('description', 'like', "%{$keyword}%");
        }

        // 4. 分类筛选
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        // 5. 分页查询
        $devices = $query->paginate($limit, ['*'], 'page', $page);

        // 6. 补充可借数量
        $devices->getCollection()->transform(function ($device) {
            $device->available_qty = $device->available_qty;
            return $device;
        });

        // 7. 返回响应
        return response()->json([
            'code' => 200,
            'message' => '获取可借设备列表成功',
            'data' => [
                'list' => $devices->items(),
                'total' => $devices->total(),
                'page' => $devices->currentPage(),
                'limit' => $devices->perPage()
            ]
        ]);
    }

    // ================================== 10. 获取设备详情 ==================================
    /**
     * 接口功能：根据ID获取单个设备的详细信息
     * 请求参数：设备ID（URL参数）
     */
    public function getDeviceDetail($id)
    {
        // 1. 根据ID查询设备（关联分类）
        $device = Device::with('category')->find($id);

        // 2. 设备不存在，返回404
        if (!$device) {
            return response()->json([
                'code' => 404,
                'message' => '设备不存在',
                'data' => []
            ]);
        }

        // 3. 补充可借数量
        $device->available_qty = $device->available_qty;

        // 4. 返回详情
        return response()->json([
            'code' => 200,
            'message' => '获取设备详情成功',
            'data' => $device
        ]);
    }

    // ================================== 11. 新增设备（仅管理员） ==================================
    /**
     * 接口功能：新增设备（仅管理员可操作）
     * 请求头：Authorization: Bearer {token}（必须是admin角色）
     * 请求参数：name(设备名称), category_id(分类ID), description(可选,描述), total_qty(总库存), status(状态)
     */
    public function addDevice(Request $request)
    {
        // 1. 验证参数（分类ID必须存在于categories表）
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'total_qty' => 'required|integer|min:0',
            'status' => 'required|in:available,maintenance,disabled'
        ]);

        // 2. 创建设备
        $device = Device::create($validated);

        // 3. 返回新增的设备信息（201 Created状态码）
        return response()->json([
            'code' => 200,
            'message' => '新增设备成功',
            'data' => $device
        ], 201);
    }
}
