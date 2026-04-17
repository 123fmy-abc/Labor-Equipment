<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Device;
use App\Models\InviteCode;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SendCodeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ZztController extends Controller
{
    // 中间件已移至路由文件中定义

    // ================================== 首次设置管理员（仅当没有管理员时可用） ==================================
    /**
     * 接口功能：首次设置管理员，仅当系统中没有管理员时可用
     * 请求参数：account, name, email, password, password_confirmation, code(验证码)
     * 注意：不需要邀请码，但系统中必须没有管理员才能使用
     */
    public function setupFirstAdmin(Request $request)
    {
        // 1. 检查是否已有管理员
        $adminExists = User::where('role', 'admin')->exists();
        if ($adminExists) {
            return response()->json([
                'code' => 403,
                'message' => '系统中已有管理员，请使用邀请码注册',
                'data' => []
            ], 403);
        }

        // 2. 验证参数（不需要验证码）
        $validated = $request->validate([
            'account' => 'required|string|unique:users,account',
            'name' => 'required|string|max:50',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^[a-zA-Z][a-zA-Z0-9]+$/'],
        ], [
            'account.required' => '账号不能为空',
            'account.unique' => '该账号已被注册',
            'name.required' => '姓名不能为空',
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'email.unique' => '该邮箱已被注册',
            'password.required' => '密码不能为空',
            'password.min' => '密码至少8位',
            'password.confirmed' => '两次密码不一致',
            'password.regex' => '密码必须以英文字母开头，且只能包含英文字母和数字',
        ]);

        // 3. 创建管理员用户
        $user = User::create([
            'account' => $validated['account'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
            'email_verified_at' => now()
        ]);

        // 5. 返回成功响应（不返回Token，需要重新登录）
        return response()->json([
            'code' => 200,
            'message' => '管理员注册成功，请使用账号密码登录',
            'data' => [
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


    // ================================== 1. 用户注册（含管理员邀请码） ==================================
    /**
     * 接口功能：用户注册，支持管理员邀请码，需要邮箱验证码
     * 请求参数：account(学号/工号), name, email, password, password_confirmation, code(验证码), invite_code(可选)
     * 邀请码逻辑：输入正确的ADMIN_INVITE_CODE → 角色设为admin；否则默认user
     */
    public function register(RegisterRequest $request)
    {
        // 1. 获取已验证的数据（FormRequest 自动验证）
        $validated = $request->validated();

        // 2. 【新增】验证邮箱验证码
        $cacheCode = cache()->get('email_code_' . $validated['email']);
        if (!$cacheCode || $cacheCode != $request->input('code')) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期',
                'data' => []
            ], 400);
        }

        // 3. 【核心】邀请码验证逻辑（新版：数据库存储的邀请码）
        $role = 'user'; // 默认角色：普通用户

        // 如果用户填写了邀请码，进行验证
        if ($request->filled('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->invite_code)->first();

            if (!$inviteCode) {
                return response()->json([
                    'code' => 400,
                    'message' => '邀请码不存在',
                    'data' => []
                ], 400);
            }

            if (!$inviteCode->isValid()) {
                return response()->json([
                    'code' => 400,
                    'message' => '邀请码已过期或已达到使用次数上限',
                    'data' => []
                ], 400);
            }

            // 邀请码有效 → 使用邀请码并设置角色
            $inviteCode->use();
            $role = $inviteCode->role;
        }

        // 4. 创建用户（密码加密存储，绝对不能明文存！）
        $user = User::create([
            'account' => $validated['account'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']), // Hash加密
            'role' => $role,
            'email_verified_at' => now()
        ]);

        // 5. 注册成功后删除验证码（防止重复使用）
        cache()->forget('email_code_' . $validated['email']);

        // 6. 生成JWT Token（登录态）
        /** @var \Tymon\JWTAuth\JWTGuard $auth */
        $auth = Auth::guard('api');
        $token = $auth->login($user);

        // 7. 返回成功响应（不返回密码，只返回必要信息）
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
        $token = Auth::guard('api')->attempt($credentials);

        // 3. 账号密码错误，抛出验证异常
        if (!$token) {
            throw ValidationException::withMessages([
                'account' => ['账号或密码错误'],
            ]);
        }

        // 4. 获取当前登录用户信息
        /** @var User $user */
        $user = Auth::guard('api')->user();

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
        Auth::guard('api')->logout();

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
        /** @var User $user */
        $user = Auth::guard('api')->user();

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

    // ================================== 6. 生成邀请码（仅超级管理员） ==================================
    /**
     * 接口功能：生成新的邀请码
     * 请求头：Authorization: Bearer {token}
     * 请求参数：role(可选,默认admin), max_uses(可选,默认1), expire_days(可选,默认30天)
     * 权限：仅超级管理员(role=admin)
     */
    public function generateInviteCode(Request $request)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 1. 检查是否登录
        if (!$user) {
            return response()->json([
                'code' => 401,
                'message' => '请先登录',
                'data' => []
            ], 401);
        }

        // 2. 权限检查：只有管理员才能生成邀请码
        if ($user->role !== 'admin') {
            return response()->json([
                'code' => 403,
                'message' => '无权限生成邀请码',
                'data' => []
            ], 403);
        }

        // 2. 验证参数
        $validated = $request->validate([
            'role' => 'nullable|in:admin,user',
            'max_uses' => 'nullable|integer|min:1|max:100',
            'expire_days' => 'nullable|integer|min:1|max:365'
        ]);

        // 3. 生成邀请码
        $inviteCode = InviteCode::createCode(
            createdBy: $user->id,
            role: $validated['role'] ?? 'admin',
            maxUses: $validated['max_uses'] ?? 1,
            expireDays: $validated['expire_days'] ?? 30
        );

        return response()->json([
            'code' => 200,
            'message' => '邀请码生成成功',
            'data' => [
                'invite_code' => $inviteCode->code,
                'role' => $inviteCode->role,
                'max_uses' => $inviteCode->max_uses,
                'expires_at' => $inviteCode->expires_at?->format('Y-m-d H:i:s'),
                'created_at' => $inviteCode->created_at->format('Y-m-d H:i:s')
            ]
        ]);
    }

    // ================================== 7. 获取邀请码列表（仅超级管理员） ==================================
    /**
     * 接口功能：获取所有邀请码列表
     * 请求头：Authorization: Bearer {token}
     * 权限：仅超级管理员(role=admin)
     */
    public function listInviteCodes()
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 1. 权限检查
        if ($user->role !== 'admin') {
            return response()->json([
                'code' => 403,
                'message' => '无权限查看邀请码',
                'data' => []
            ], 403);
        }

        // 2. 获取邀请码列表
        $inviteCodes = InviteCode::with('creator:id,account,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($code) {
                return [
                    'id' => $code->id,
                    'code' => $code->code,
                    'role' => $code->role,
                    'max_uses' => $code->max_uses,
                    'used_count' => $code->used_count,
                    'expires_at' => $code->expires_at?->format('Y-m-d H:i:s'),
                    'is_valid' => $code->isValid(),
                    'created_by' => $code->creator?->name ?? '未知',
                    'created_at' => $code->created_at->format('Y-m-d H:i:s'),
                    'used_at' => $code->used_at?->format('Y-m-d H:i:s')
                ];
            });

        return response()->json([
            'code' => 200,
            'message' => '获取邀请码列表成功',
            'data' => $inviteCodes
        ]);
    }

    // ================================== 8. 删除邀请码（仅超级管理员） ==================================
    /**
     * 接口功能：删除邀请码
     * 请求头：Authorization: Bearer {token}
     * 请求参数：id(邀请码ID)
     * 权限：仅超级管理员(role=admin)
     */
    public function deleteInviteCode($id)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 1. 权限检查
        if ($user->role !== 'admin') {
            return response()->json([
                'code' => 403,
                'message' => '无权限删除邀请码',
                'data' => []
            ], 403);
        }

        // 2. 查找并删除邀请码
        $inviteCode = InviteCode::find($id);

        if (!$inviteCode) {
            return response()->json([
                'code' => 404,
                'message' => '邀请码不存在',
                'data' => []
            ], 404);
        }

        $inviteCode->delete();

        return response()->json([
            'code' => 200,
            'message' => '邀请码删除成功',
            'data' => []
        ]);
    }

    // ================================== 9. 修改个人资料 ==================================
    /**
     * 接口功能：修改个人信息/密码
     * 请求头：Authorization: Bearer {token}
     * 请求参数：name(可选), email(可选), password(可选), password_confirmation(可选), old_password(改密码必填)
     */
    public function updateProfile(Request $request)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 1. 验证参数（改密码时必须验证旧密码）
        $validated = $request->validate([
            'name' => 'nullable|string|max:50',
            'email' => 'nullable|email|unique:users,email,' . $user->id, // 排除当前用户的邮箱
            'password' => 'nullable|string|min:6|confirmed',
            'old_password' => 'required_if:password,!=null|string' // 改密码时，旧密码必填
        ]);

        // 2. 如果用户要修改密码，先验证旧密码是否正确
        $updateData = [];
        if ($request->filled('password')) {
            if (!Hash::check($validated['old_password'], $user->password)) {
                return response()->json([
                    'code' => 400,
                    'message' => '旧密码错误',
                    'data' => []
                ]);
            }
            // 旧密码正确，加密新密码
            $updateData['password'] = Hash::make($validated['password']);
        }

        // 3. 更新姓名、邮箱（如果有传参）
        if ($request->filled('name')) {
            $updateData['name'] = $validated['name'];
        }
        if ($request->filled('email')) {
            $updateData['email'] = $validated['email'];
        }

        // 4. 保存修改
        $user->update($updateData);

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
    public function sendEmailCode(SendCodeRequest $request)
    {
        // 1. 获取已验证的邮箱（FormRequest 自动验证）
        $email = $request->validated()['email'];

        // 2. 生成6位随机验证码
        $code = rand(100000, 999999);

        // 3. 把验证码存入缓存（key=email_code_邮箱，有效期300秒=5分钟）
        cache()->put('email_code_' . $email, $code, 300);

        // 4. 发送邮件（try-catch捕获异常，避免邮件服务崩溃导致接口报错）
        try {
            Mail::raw("您的实验室设备系统验证码是：{$code}，5分钟内有效，请勿泄露给他人。", function ($message) use ($email) {
                $message->to($email)
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

        // 3. 返回详情（available_qty 通过模型访问器自动计算）
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

        // 3. 返回新增的设备信息
        return response()->json([
            'code' => 200,
            'message' => '新增设备成功',
            'data' => $device
        ], 200);
    }
}
