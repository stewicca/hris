<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class ApiLog extends Model
{
    /** Rows are pruned by the scheduled `model:prune` command. */
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'method',
        'path',
        'source_ip',
        'user_agent',
        'content_type',
        'request_headers',
        'query_params',
        'request_body',
        'status_code',
        'response_headers',
        'response_body',
        'duration_ms',
    ];

    /**
     * Anything older than the configured retention window.
     *
     * Without this the table grows without bound, which on a small disk turns
     * any sustained request volume into an outage.
     *
     * @return Builder<$this>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays(config('hris.api_log_retention_days')),
        );
    }

    public function casts(): array
    {
        return [
            'request_headers' => 'array',
            'query_params' => 'array',
            'response_headers' => 'array',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
