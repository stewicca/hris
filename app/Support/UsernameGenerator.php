<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class UsernameGenerator
{
    /**
     * Generate a unique and professional username based on name.
     */
    public static function generate(string $name): string
    {
        $name = Str::lower($name);
        // Remove special characters, keep only letters and numbers
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $parts = array_values(array_filter(explode(' ', $name)));

        if (empty($parts)) {
            return self::ensureUnique('user_'.Str::random(4));
        }

        $candidates = self::generateCandidates($parts);

        foreach ($candidates as $candidate) {
            // Ensure at least 3 characters as requested
            if (strlen($candidate) >= 3 && ! self::isTaken($candidate)) {
                return $candidate;
            }
        }

        // Fallback with random numbers (2 to 4 digits)
        $base = $candidates[0]; // Usually the first name
        $attempts = 0;

        while ($attempts < 100) {
            // Length 2 or 4 as per "min 2 max 4"
            $len = rand(1, 2) * 2;
            $number = rand(pow(10, $len - 1), pow(10, $len) - 1);

            // Try to keep it within a reasonable length (max 10-12)
            $candidate = substr($base, 0, 10 - $len).$number;

            // Ensure at least 3 characters even in fallback
            if (strlen($candidate) >= 3 && ! self::isTaken($candidate)) {
                return $candidate;
            }
            $attempts++;
        }

        // Absolute fallback (guaranteed length)
        return self::ensureUnique(Str::padRight($base, 3, '0').rand(1000, 9999));
    }

    /**
     * Generate potential username candidates from name parts.
     */
    protected static function generateCandidates(array $parts): array
    {
        $candidates = [];
        $firstName = $parts[0];

        // 1. Simple first name (max 8 chars)
        $candidates[] = substr($firstName, 0, 8);

        // 2. First name + initials of subsequent names (e.g., stevenwa)
        if (count($parts) > 1) {
            $current = $firstName;
            for ($i = 1; $i < count($parts); $i++) {
                $current .= substr($parts[$i], 0, 1);
                $candidates[] = substr($current, 0, 8);
            }
        }

        // 3. Combined parts (e.g., stewicalf - first 2 chars of each)
        if (count($parts) > 1) {
            $combined2 = '';
            foreach ($parts as $part) {
                $combined2 .= substr($part, 0, 2);
            }
            $candidates[] = substr($combined2, 0, 10);
        }

        // 4. Reverse: Last name + first initial
        if (count($parts) > 1) {
            $lastName = end($parts);
            $candidates[] = substr($lastName.substr($firstName, 0, 1), 0, 8);
        }

        // 5. Full slug without separators (limited length)
        $candidates[] = substr(implode('', $parts), 0, 12);

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Check if a username is already taken.
     */
    protected static function isTaken(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    /**
     * Ensure uniqueness by appending a random string if all else fails.
     */
    protected static function ensureUnique(string $username): string
    {
        while (self::isTaken($username)) {
            $username .= rand(0, 9);
        }

        return $username;
    }
}
