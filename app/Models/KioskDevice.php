<?php

namespace App\Models;

use Database\Factories\KioskDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An attendance terminal installed at a company site.
 */
class KioskDevice extends Model
{
    /** @use HasFactory<KioskDeviceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'location',
        'token_hash',
        'allowed_ips',
        'is_active',
        'last_seen_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    public function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Create a device together with its one-time plaintext token.
     *
     * The plaintext is never stored, so the returned value is the only moment
     * it exists; a lost token is re-issued, not recovered. It is handed back
     * separately rather than set on the model, so it can never be mistaken for
     * an attribute and written back to a column that does not exist.
     *
     * @param  list<string>  $allowedIps
     * @return array{device: self, token: string}
     */
    public static function issue(string $name, ?string $location = null, array $allowedIps = []): array
    {
        $plain = Str::random(64);

        $device = static::create([
            'name' => $name,
            'location' => $location,
            'token_hash' => static::hashToken($plain),
            'allowed_ips' => $allowedIps === [] ? null : $allowedIps,
            'is_active' => true,
        ]);

        return ['device' => $device, 'token' => $plain];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Resolve an active device from a presented token, or null.
     */
    public static function findByToken(string $plainToken): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        return static::query()
            ->where('token_hash', static::hashToken($plainToken))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Whether this device is allowed to submit from the given address.
     *
     * An empty or null allowlist means the check is not configured yet, which
     * is permissive by design: the office's public address is often not known
     * on the day the terminal is set up, and an allowlist that blocks the
     * terminal it was meant to protect just gets deleted in a hurry.
     */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips;

        if (empty($allowed)) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $allowed);
    }
}
