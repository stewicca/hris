<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Terminals that may record attendance without an employee session.
     *
     * A kiosk authenticates as a device, not as a person: the face identifies
     * who is checking in, and the device token proves the request came from a
     * terminal the company installed rather than from anyone who found the URL.
     */
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();

            // SHA-256 of the issued token. A plain hash rather than a password
            // hash on purpose: the token is 64 random characters, so there is
            // nothing to brute-force, and lookup has to be a single indexed
            // query rather than a scan over every row.
            $table->string('token_hash', 64)->unique();

            // Optional IP/CIDR allowlist. Null means "any network". This is the
            // location control for a fixed terminal — far harder to forward to
            // someone at home than a URL, and unlike GPS it works indoors on a
            // device that has no satellite receiver.
            $table->json('allowed_ips')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
