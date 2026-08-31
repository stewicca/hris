<?php

namespace App\Http\Requests\Attendance;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note what is deliberately not constrained: the order of the times. A
     * night shift legitimately checks out at 06:00 having checked in at 22:00,
     * so requiring check_out to fall after check_in would reject the correct
     * data for every employee on nights.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'required',
                Rule::exists('employees', 'id')->where('status', 'active'),
            ],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(Attendance::MANUAL_STATUSES)],
            'check_in' => ['nullable', 'required_if:status,present', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_end'],
            'break_end' => ['nullable', 'date_format:H:i', 'required_with:break_start'],
            'notes' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), Attendance::EXCUSED_STATUSES, true)),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Karyawan tidak ditemukan atau sudah tidak aktif.',
            'date.before_or_equal' => 'Tanggal tidak boleh melebihi hari ini.',
            'status.in' => 'Status kehadiran tidak dikenali.',
            'check_in.required_if' => 'Jam masuk wajib diisi untuk kehadiran.',
            'check_in.date_format' => 'Format jam masuk harus HH:MM.',
            'check_out.date_format' => 'Format jam pulang harus HH:MM.',
            'break_start.required_with' => 'Isi jam mulai istirahat juga.',
            'break_end.required_with' => 'Isi jam selesai istirahat juga.',
            'break_start.date_format' => 'Format jam istirahat harus HH:MM.',
            'break_end.date_format' => 'Format jam istirahat harus HH:MM.',
            'notes.required' => 'Keterangan wajib diisi untuk izin atau sakit.',
        ];
    }
}
