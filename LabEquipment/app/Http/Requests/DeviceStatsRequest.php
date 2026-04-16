<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceStatsRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user() && $this->user()->isAdmin();
    }

    public function rules()
    {
        return [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'device_id' => 'nullable|exists:devices,id'
        ];
    }

    public function messages()
    {
        return [
            'start_date.date' => '开始日期格式不正确',
            'end_date.date' => '结束日期格式不正确',
            'end_date.after_or_equal' => '结束日期不能早于开始日期',
            'device_id.exists' => '指定的设备不存在'
        ];
    }

    public function getStatsParams()
    {
        return [
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'device_id' => $this->device_id
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $response = response()->json([
            'code' => 422,
            'message' => '参数验证失败',
            'errors' => $validator->errors()
        ], 422);

        throw new \Illuminate\Validation\ValidationException($validator, $response);
    }
}
