<?php

namespace App\Models;

use App\AttendanceEventType;
use Database\Factories\AttendanceEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    /** @use HasFactory<AttendanceEventFactory> */
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'type',
        'occurred_at',
        'lat',
        'lng',
        'accuracy',
        'photo_path',
        'face_verified',
        'notes',
    ];

    public function casts(): array
    {
        return [
            'type' => AttendanceEventType::class,
            'occurred_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'float',
            'face_verified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
