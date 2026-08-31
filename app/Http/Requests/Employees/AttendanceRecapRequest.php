<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRecapRequest extends FormRequest
{
    /**
     * The longest span the sheet will build. A recap covering more than a year
     * is not a recap, and the cap keeps one mistyped year from walking every
     * attendance row the installation has.
     */
    public const int MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'date'],
            'end' => [
                'required',
                'date',
                'after_or_equal:start',
                'before_or_equal:today',
            ],
            // Filtered by name, matching the attendance board's own filter.
            'department' => ['nullable', Rule::exists('departments', 'name')],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Both ends are inclusive, so a span is one more day than
                // the difference between its ends.
                if ($this->date('start')->diffInDays($this->date('end')) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end', 'Rentang maksimal '.self::MAX_DAYS.' hari.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start.required' => 'Tanggal mulai wajib diisi.',
            'start.date' => 'Tanggal mulai tidak valid.',
            'end.required' => 'Tanggal akhir wajib diisi.',
            'end.date' => 'Tanggal akhir tidak valid.',
            'end.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
            'end.before_or_equal' => 'Rekap tidak bisa dibuat untuk tanggal yang belum terjadi.',
            'department.exists' => 'Departemen tidak ditemukan.',
        ];
    }
}
