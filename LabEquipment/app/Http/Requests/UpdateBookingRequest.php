<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => [
                'sometimes',
                'integer',
                Rule::exists('devices', 'id')->where('status', 'available'),
            ],
            'start_date' => [
                'sometimes',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'end_date' => [
                'sometimes',
                'date_format:Y-m-d',
            ],
            'status' => [
                'sometimes',
                'in:pending,approved,rejected,returned',
            ],
            'purpose' => [
                'sometimes',
                'string',
                'min:2',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.integer' => '设备ID必须是整数',
            'device_id.exists' => '所选设备不存在或当前不可借用',
            'start_date.date_format' => '起始时间格式不正确，应为 YYYY-MM-DD',
            'start_date.after_or_equal' => '起始时间不能早于今天',
            'end_date.date_format' => '结束时间格式不正确，应为 YYYY-MM-DD',
            'status.in' => '状态值不正确',
            'purpose.string' => '借用用途必须是字符串',
            'purpose.min' => '借用用途至少2个字符',
            'purpose.max' => '借用用途不能超过500个字符',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->failed()) {
                return;
            }

            $data = $this->validated();

            // 如果传了 start_date 和 end_date，检查结束日期是否晚于开始日期
            if (isset($data['start_date']) && isset($data['end_date'])) {
                if ($data['end_date'] < $data['start_date']) {
                    $validator->errors()->add('end_date', '结束时间不能早于起始时间');
                }
            }
        });
    }
}
