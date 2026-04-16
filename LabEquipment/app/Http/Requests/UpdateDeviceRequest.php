<?php

namespace App\Http\Requests;

use App\Models\Device;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    /**
     * 授权验证
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id',
            'description' => 'nullable|string',
            'total_qty' => 'sometimes|integer|min:0',
            'status' => ['sometimes', Rule::in(['available', 'maintenance', 'disabled'])]
        ];
    }

    /**
     * 自定义错误消息
     */
    public function messages(): array
    {
        return [
            'name.string' => '设备名称必须是字符串',
            'name.max' => '设备名称不能超过255个字符',
            'category_id.exists' => '选择的分类不存在',
            'total_qty.integer' => '总库存必须是整数',
            'total_qty.min' => '总库存不能为负数',
            'status.in' => '设备状态必须是 available、maintenance 或 disabled'
        ];
    }

    /**
     * 预处理数据
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if ($this->has('name') && empty(trim($this->name))) {
            unset($data['name']);
        }
        if ($this->has('description') && empty(trim($this->description))) {
            $data['description'] = null;
        }

        $this->replace($data);
    }

    /**
     * 注入额外的业务规则验证
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $device = Device::find($this->route('id'));

            if (!$device) {
                $validator->errors()->add('device', '设备不存在');
                return;
            }

            // 1. 检查库存修改（不能小于已借出数量）
            if ($this->has('total_qty') && !$validator->errors()->has('total_qty')) {
                $borrowedCount = Booking::where('device_id', $device->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->count();

                if ($this->total_qty < $borrowedCount) {
                    $validator->errors()->add('total_qty',
                        "总库存({$this->total_qty})不能小于已借出数量({$borrowedCount})"
                    );
                }
            }

            // 2. 检查状态修改（改为 disabled 时，不能有未完成的借用）
            if ($this->has('status') && $this->status === 'disabled' && $device->status !== 'disabled') {
                $hasActiveBookings = Booking::where('device_id', $device->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists();

                if ($hasActiveBookings) {
                    $validator->errors()->add('status',
                        '设备当前有未完成的借用记录，无法禁用'
                    );
                }
            }
        });
    }

    /**
     * 获取验证通过后的数据
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (is_null($key) && is_array($validated)) {
            return array_intersect_key($validated, array_flip([
                'name', 'category_id', 'description', 'total_qty', 'status'
            ]));
        }

        return $validated;
    }

    /**
     * 自定义验证失败响应格式
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $response = response()->json([
            'code' => 422,
            'message' => '验证失败',
            'errors' => $validator->errors()
        ], 422);

        throw new \Illuminate\Validation\ValidationException($validator, $response);
    }
}
