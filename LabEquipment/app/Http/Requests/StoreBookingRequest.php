<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'device_id' => [
                'required',
                'integer',
                Rule::exists('devices', 'id')->where('status', 'available'),
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',//限制日期不能早于今天
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',//限制日期不能早于起始日期
            ],
            'purpose' => [
                'required',
                'string',
                'min:2',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.required' => '借用设备id不能为空',
            'device_id.integer' => '设备ID必须是整数',
            'device_id.exists' => '所选设备不存在或当前不可借用',
            'start_date.required' => '起始时间不能为空',
            'start_date.date_format' => '起始时间格式不正确，应为 YYYY-MM-DD',
            'start_date.after_or_equal' => '起始时间不能早于今天',
            'end_date.required' => '结束时间不能为空',
            'end_date.date_format' => '结束时间格式不正确，应为 YYYY-MM-DD',
            'end_date.after_or_equal' => '结束时间不能早于起始时间',
            'purpose.required' => '用途不能为空',
            'purpose.min' => '用途至少2个字符',
            'purpose.max' => '用途不能超过500个字符',
        ];
    }
}
