<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'device_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $device = \App\Models\Device::find($value);

                    if (!$device) {
                        $fail('所选设备不存在');
                        return;
                    }

                    if ($device->status === 'maintenance') {
                        $fail('设备正在维护中，暂时不可借用');
                        return;
                    }

                    if ($device->status === 'disabled') {
                        $fail('设备已下架，不可借用');
                        return;
                    }

                    if ($device->status !== 'available') {
                        $fail('设备当前不可借用');
                    }
                },
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    $today = now()->format('Y-m-d');
                    if ($value < $today) {
                        $fail('起始日期不能早于今天');
                    }
                },
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) {
                    $deviceId = request()->input('device_id');
                    if (!$deviceId) {
                        return;
                    }
                    $device = \App\Models\Device::find($deviceId);
                    if ($device && $value > $device->total_qty) {
                        $fail('借用数量不能超过设备总库存（当前库存：' . $device->total_qty . '）');
                    }
                },
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
            'device_id.required' => '请选择借用设备',
            'device_id.integer' => '设备ID必须是整数',
            'device_id.exists' => '所选设备不存在或当前不可借用',
            'quantity.required' => '请输入借用数量',
            'quantity.integer' => '借用数量必须是整数',
            'quantity.min' => '借用数量至少为1',
            'start_date.required' => '请选择起始日期',
            'start_date.date_format' => '起始日期格式不正确，应为 YYYY-MM-DD',
            'start_date.after_or_equal' => '起始日期不能早于今天',
            'end_date.required' => '请选择结束日期',
            'end_date.date_format' => '结束日期格式不正确，应为 YYYY-MM-DD',
            'end_date.after_or_equal' => '结束日期不能早于起始日期',
            'purpose.required' => '请填写借用用途',
            'purpose.string' => '借用用途必须是字符串',
            'purpose.min' => '借用用途不能小于2个字符',
            'purpose.max' => '借用用途不能超过500个字符',
        ];
    }
    /**
     * 处理授权失败
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'code' => 401,
                'message' => '请先登录后再操作',
                'data' => null
            ], 401)
        );
    }
}
