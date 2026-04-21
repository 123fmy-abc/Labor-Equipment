<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Device;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SendCodeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ZztController extends Controller
{
    // 中间件已移至路由文件中定义

    /**
     * 用户注册
     * 请求参数：account(学号/工号), name, email, password, password_confirmation, code(验证码)
     * 默认角色为 user
     */
    public function register(RegisterRequest $request)
    {
        // 1. 获取已验证的数据（FormRequest 自动验证）
        $validated = $request->validated();

        // 2. 验证邮箱验证码
        $cacheCode = cache()->get('email_code_' . $validated['email']);
        if (!$cacheCode || $cacheCode != $request->input('code')) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期',
                'data' => []
            ], 400);
        }

        // 3. 创建用户（密码加密存储，role 默认为 user）
        $user = User::create([
            'account' => $validated['account'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
            'email_verified_at' => now()
        ]);

        // 4. 注册成功后删除验证码（防止重复使用）
        cache()->forget('email_code_' . $validated['email']);

        // 5. 返回成功响应（不返回Token，需要重新登录）
        return response()->json([
            'code' => 200,
            'message' => '注册成功，请使用账号密码登录',
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

    // ================================== 2. 用户登录 ==================================
    /**
     * 接口功能：用户登录，返回Token
     * 请求参数：account(账号), password(密码)
     */
    public function login(Request $request)
    {
        // 1. 验证参数
        $validated = $request->validate([
            'account' => 'required|string|size:11|regex:/^\d{11}$/',
            'password' => ['required', 'string', 'min:8', 'regex:/^[a-zA-Z][a-zA-Z0-9]*$/']
        ], [
            'account.required' => '账号不能为空',
            'account.size' => '账号必须为11位数字',
            'account.regex' => '账号必须为11位数字',
            'password.required' => '密码不能为空',
            'password.min' => '密码至少8位',
            'password.regex' => '密码必须以英文字母开头，且只能包含英文字母和数字'
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

        // 5. 单点登录：将之前的Token加入黑名单
        $previousToken = cache()->get('user_token_' . $user->id);
        if ($previousToken) {
            try {
                // 使用JWTAuth facade使旧Token失效（true表示立即生效）
                JWTAuth::setToken($previousToken)->invalidate(true);
            } catch (\Exception $e) {
                // 忽略已过期或无效的Token错误
            }
        }
        // 存储新Token到缓存（30天有效期）
        cache()->put('user_token_' . $user->id, $token, now()->addDays(30));

        // 6. 返回成功响应
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
        // 获取当前用户并清除Token缓存
        $user = Auth::guard('api')->user();
        if ($user) {
            cache()->forget('user_token_' . $user->id);
        }

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
                'role' => $user->role,
                'college' => $user->college,
                'major' => $user->major,
                'avatar' => $user->avatar
            ]
        ]);
    }

    /**
     * 修改个人资料
     * 请求头：Authorization: Bearer {token}
     * 请求参数：name(可选), email(可选), password(可选), password_confirmation(可选), old_password(改密码必填)
     */

    /**
     * 修改个人资料
    /**
     * 接口功能：修改个人信息/密码
     * 请求头：Authorization: Bearer {token}
     * 请求参数：
     *   - account(可选): 账户，唯一，英文开头
     *   - name(可选): 姓名，2-50字符
     *   - email(可选): 邮箱，需验证唯一性，修改时需要提供 email_code
     *   - email_code(修改邮箱时必填): 新邮箱的验证码
     *   - password(可选): 新密码，至少8位，需包含字母和数字
     *   - password_confirmation(可选): 确认新密码
     *   - old_password(改密码时必填): 旧密码
     *   - college(可选): 学院，2-100字符
     *   - major(可选): 专业，2-100字符
     *   - avatar(可选): 头像URL，最大500字符
     */
    public function updateProfile(Request $request)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 过滤掉和原值相同的字段（不传这些字段就不会触发验证）
        $input = $request->all();
        if (isset($input['account']) && $input['account'] === $user->account) {
            unset($input['account']);
        }
        if (isset($input['name']) && $input['name'] === $user->name) {
            unset($input['name']);
        }
        if (isset($input['email']) && $input['email'] === $user->email) {
            unset($input['email']);
        }
        if (isset($input['college']) && $input['college'] === $user->college) {
            unset($input['college']);
        }
        if (isset($input['major']) && $input['major'] === $user->major) {
            unset($input['major']);
        }
        if (isset($input['avatar']) && $input['avatar'] === $user->avatar) {
            unset($input['avatar']);
        }
        $request->replace($input);

        // 验证参数
        $validated = $request->validate([
            'account' => 'nullable|string|size:11|regex:/^\d{11}$/|unique:users,account,' . $user->id,
            'name' => 'nullable|string|min:2|max:50',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'email_code' => 'required_with:email|string|size:6',
            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
                'regex:/^[a-zA-Z][a-zA-Z0-9]*$/'
            ],
            'old_password' => 'required_with:password|string',
            'college' => 'nullable|string|min:2|max:100',
            'major' => 'nullable|string|min:2|max:100',
            'avatar' => 'nullable|string|max:500|url'
        ], [
            'account.size' => '账户必须为11位数字',
            'account.regex' => '账户必须为11位数字',
            'account.unique' => '该账户已被使用',
            'name.min' => '姓名至少2个字符',
            'name.max' => '姓名最多50个字符',
            'email.email' => '邮箱格式不正确',
            'email.unique' => '该邮箱已被其他用户使用',
            'email_code.required_with' => '修改邮箱时必须提供验证码',
            'email_code.size' => '验证码必须是6位',
            'password.min' => '新密码至少8位',
            'password.confirmed' => '两次输入的新密码不一致',
            'password.regex' => '新密码必须同时包含英文字母和数字',
            'old_password.required_with' => '修改密码时必须提供旧密码',
            'college.min' => '学院名称至少2个字符',
            'college.max' => '学院名称最多100个字符',
            'major.min' => '专业名称至少2个字符',
            'major.max' => '专业名称最多100个字符',
            'avatar.max' => '头像URL最多500个字符',
            'avatar.url' => '头像URL格式不正确'
        ]);

        $updateData = [];
        $updatedFields = [];

        // 更新账户
        if ($request->has('account') && $validated['account'] !== $user->account) {
            $updateData['account'] = $validated['account'];
            $updatedFields[] = 'account';
        }

        // 更新姓名
        if ($request->has('name') && $validated['name'] !== $user->name) {
            $updateData['name'] = $validated['name'];
            $updatedFields[] = 'name';
        }

        // 更新学院
        if ($request->has('college') && $validated['college'] !== $user->college) {
            $updateData['college'] = $validated['college'];
            $updatedFields[] = 'college';
        }

        // 更新专业
        if ($request->has('major') && $validated['major'] !== $user->major) {
            $updateData['major'] = $validated['major'];
            $updatedFields[] = 'major';
        }

        // 更新头像
        if ($request->has('avatar') && $validated['avatar'] !== $user->avatar) {
            $updateData['avatar'] = $validated['avatar'];
            $updatedFields[] = 'avatar';
        }

        // 更新邮箱
        if ($request->has('email') && $validated['email'] !== $user->email) {
            // 验证邮箱验证码
            $cacheCode = cache()->get('email_code_' . $validated['email']);
            if (!$cacheCode || $cacheCode != $request->input('email_code')) {
                return response()->json([
                    'code' => 400,
                    'message' => '邮箱验证码错误或已过期',
                    'data' => ['error_field' => 'email_code']
                ], 400);
            }

            $updateData['email'] = $validated['email'];
            $updateData['email_verified_at'] = now(); // 验证通过后设为当前时间
            $updatedFields[] = 'email';

            // 删除已使用的验证码
            cache()->forget('email_code_' . $validated['email']);
        }

        // 更新密码
        if ($request->filled('password')) {
            if (!$request->filled('old_password')) {
                return response()->json([
                    'code' => 400,
                    'message' => '修改密码时必须提供旧密码',
                    'data' => ['error_field' => 'old_password']
                ], 400);
            }

            if (!Hash::check($request->input('old_password'), $user->password)) {
                return response()->json([
                    'code' => 400,
                    'message' => '旧密码错误',
                    'data' => ['error_field' => 'old_password']
                ], 400);
            }

            if (Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'code' => 400,
                    'message' => '新密码不能与旧密码相同',
                    'data' => ['error_field' => 'password']
                ], 400);
            }

            $updateData['password'] = Hash::make($validated['password']);
            $updatedFields[] = 'password';
        }

        // 保存修改
        if (!empty($updateData)) {
            $user->update($updateData);
        }

        return response()->json([
            'code' => 200,
            'message' => empty($updatedFields) ? '资料无变化' : '资料修改成功',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'account' => $user->account,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at?->format('Y-m-d H:i:s'),
                    'college' => $user->college,
                    'major' => $user->major,
                    'avatar' => $user->avatar
                ],
                'updated_fields' => $updatedFields
            ]
        ]);
    }

    // ================================== 5. 上传头像 ==================================
    /**
     * 接口功能：上传用户头像，支持 jpg/png/gif 格式，最大 2MB
     * 请求头：Authorization: Bearer {token}
     * 请求参数：avatar (file 类型)
     * 返回：头像访问 URL
     */
    public function uploadAvatar(Request $request)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // 验证上传文件
        $validated = $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048'
        ], [
            'avatar.required' => '请选择要上传的头像文件',
            'avatar.image' => '上传的文件必须是图片',
            'avatar.mimes' => '头像格式必须是 jpg、png 或 gif',
            'avatar.max' => '头像文件大小不能超过 2MB'
        ]);

        try {
            // 获取上传的文件
            $file = $request->file('avatar');

            // 生成唯一文件名：user_{user_id}_{时间戳}.{扩展名}
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            // 存储到 public/avatars 目录
            $path = $file->storeAs('avatars', $filename, 'public');

            // 生成访问 URL
            $url = asset('storage/' . $path);

            return response()->json([
                'code' => 200,
                'message' => '头像上传成功',
                'data' => [
                    'url' => $url,
                    'filename' => $filename
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '头像上传失败：' . $e->getMessage(),
                'data' => []
            ], 500);
        }
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
     * 接口功能：获取所有设备列表，支持关键词搜索，分页
     * 请求参数：keyword(可选，关键词), page(可选，页码), limit(可选，每页数量)
     */
    public function getDeviceList(Request $request)
    {
        // 1. 获取请求参数，设置默认值
        $keyword = $request->input('keyword', '');
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 10);

        // 2. 构建查询
        $query = Device::query();

        // 3. 关键词筛选（设备名称/描述模糊匹配）
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        // 4. 分页查询
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
        $query = Device::where('status', 'available')
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
            'description' => 'required|string',
            'total_qty' => 'required|integer|min:0',
            'status' => 'required|in:available,maintenance,disabled'
        ], [
            'name.required' => '设备名称不能为空',
            'name.max' => '设备名称最多100个字符',
            'category_id.required' => '分类ID不能为空',
            'category_id.exists' => '所选分类不存在',
            'description.required'=>'设备描述不能为空',
            'total_qty.required' => '总库存不能为空',
            'total_qty.integer' => '总库存必须是整数',
            'total_qty.min' => '总库存不能小于0',
            'status.required' => '设备状态不能为空',
            'status.in' => '设备状态必须是 available、maintenance 或 disabled',
        ]);

        // 2. 获取当前登录用户并记录创建者
        $user = auth()->user();
        $validated['created_by'] = $user->id;

        // 3. 根据状态设置可用库存
        // 只有状态为 available 时，可用库存才等于总库存
        // 其他状态（maintenance, disabled）可用库存为 0
        $validated['available_qty'] = $validated['status'] === 'available' 
            ? $validated['total_qty'] 
            : 0;

        // 4. 创建设备
        $device = Device::create($validated);

        // 4. 返回新增的设备信息
        return response()->json([
            'code' => 200,
            'message' => '新增设备成功',
            'data' => $device
        ], 200);
    }
}
