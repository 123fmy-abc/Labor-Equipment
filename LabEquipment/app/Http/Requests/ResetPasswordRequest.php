<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ];
    }

    /**
     * 错误消息
     */
    public function messages(): array
    {
        return [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'email.exists' => '该邮箱未注册',
            'code.required' => '验证码不能为空',
            'code.size' => '验证码必须是6位',
            'password.required' => '密码不能为空',
            'password.min' => '密码至少6位',
            'password.confirmed' => '两次密码不一致',
        ];
    }
}
