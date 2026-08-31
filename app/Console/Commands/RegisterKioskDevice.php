<?php

namespace App\Console\Commands;

use App\Models\KioskDevice;
use Illuminate\Console\Command;

class RegisterKioskDevice extends Command
{
    protected $signature = 'kiosk:register
        {name : A label for the terminal, e.g. "Lobi Utama"}
        {--location= : Where it is installed, shown on the terminal itself}
        {--ip=* : IP address or CIDR range the terminal may submit from; repeatable}';

    protected $description = 'Issue a token for a new attendance kiosk terminal';

    public function handle(): int
    {
        ['device' => $device, 'token' => $token] = KioskDevice::issue(
            name: $this->argument('name'),
            location: $this->option('location'),
            allowedIps: $this->option('ip'),
        );

        $this->info("Terminal #{$device->id} \"{$device->name}\" terdaftar.");
        $this->newLine();
        $this->line('Token (disimpan hanya sebagai hash — salin sekarang, tidak bisa dilihat lagi):');
        $this->line("  {$token}");
        $this->newLine();

        if (empty($device->allowed_ips)) {
            $this->warn('Tanpa batasan IP: token ini berlaku dari jaringan mana pun.');
            $this->line('Setelah alamat IP kantor diketahui, jalankan ulang dengan --ip=<alamat/CIDR>.');
        } else {
            $this->line('Dibatasi ke: '.implode(', ', $device->allowed_ips));
        }

        return self::SUCCESS;
    }
}
