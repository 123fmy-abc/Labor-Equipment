<?php

namespace App\Http\Requests;

use App\Models\Device;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

class DeleteDeviceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user() && $this->user()->isAdmin();
    }

    public function rules()
    {
        return [];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $device = Device::find($this->route('id'));

            if (!$device) {
                $validator->errors()->add('device', '设备不存在');
                return;
            }

            $hasActiveBookings = Booking::where('device_id', $device->id)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($hasActiveBookings) {
                $validator->errors()->add('device', '该设备有未完成的借用记录，无法删除');
            }
        });
    }

    public function getDevice()
    {
        return Device::find($this->route('id'));
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $response = response()->json([
            'code' => 422,
            'message' => '验证失败',
            'errors' => $validator->errors()
        ], 422);

        throw new \Illuminate\Validation\ValidationException($validator, $response);
    }
}
