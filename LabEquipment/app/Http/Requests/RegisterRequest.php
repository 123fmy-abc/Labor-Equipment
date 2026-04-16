<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * 是否允许访问
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            'account' => 'required|string|unique:users|max:20',
            'name' => 'required|string|max:50',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'code' => 'required|string|size:6', // 邮箱验证码，6位
            'invite_code' => 'nullable|string',
        ];
    }

    /**
     * 错误消息
     */
    public function messages(): array
    {
        return [
            'account.required' => '账号不能为空',
            'account.unique' => '该账号已被注册',
            'name.required' => '姓名不能为空',
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'email.unique' => '该邮箱已被注册',
            'password.required' => '密码不能为空',
            'password.min' => '密码至少6位',
            'password.confirmed' => '两次密码不一致',
            'code.required' => '验证码不能为空',
            'code.size' => '验证码必须是6位',
        ];
    }
}
