<?php

namespace Database\Factories;

use App\Models\KioskDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KioskDevice>
 */
class KioskDeviceFactory extends Factory
{
    protected $model = KioskDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Terminal '.fake()->word(),
            'location' => 'Lobi',
            'token_hash' => KioskDevice::hashToken(Str::random(64)),
            'allowed_ips' => null,
            'is_active' => true,
            'last_seen_at' => null,
        ];
    }

    /**
     * Pin the device to a known plaintext token so a test can present it as a
     * header. The plaintext is unrecoverable from a persisted device, so it has
     * to be chosen up front rather than read back.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn () => ['token_hash' => KioskDevice::hashToken($plainToken)]);
    }

    /**
     * A device restricted to specific addresses or CIDR ranges.
     *
     * @param  list<string>  $ips
     */
    public function restrictedTo(array $ips): static
    {
        return $this->state(fn () => ['allowed_ips' => $ips]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
