<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;

/**
 * Identifies an employee from a single face photo (1:N), for the unattended
 * kiosk terminal.
 *
 * The authenticated flows verify 1:1 — identity comes from the session and the
 * face merely confirms it. A kiosk has no session, so the face itself has to
 * answer "who is this?" against every enrolled employee at once.
 *
 * That is a materially harder problem. With a single fixed threshold the odds
 * of a false accept grow with the size of the roster, because every additional
 * enrolled face is one more chance to be the nearest match. So two guards
 * apply instead of one:
 *
 *  - the winner must be nearer than the kiosk threshold, which is deliberately
 *    stricter than the 1:1 threshold used by the logged-in flows;
 *  - it must beat the runner-up by a margin, so two similar-looking employees
 *    resolve to "not sure" rather than silently to whichever won by a hair.
 *
 * Matching runs here rather than in the Python service on purpose: the
 * embeddings already live in MySQL, the arithmetic is one dot product over 512
 * floats, and keeping it in PHP puts the decision rules under the test suite
 * that guards the rest of attendance.
 */
class FaceMatcher
{
    /**
     * Cache key for the enrolled roster. Invalidated from {@see Employee::booted()}
     * whenever an employee is saved or deleted, so a fresh enrollment is usable
     * on the very next scan.
     */
    public const string CACHE_KEY = 'kiosk:face-embeddings';

    /**
     * Identify the face in the given photo.
     *
     * `reason` is null on success and otherwise names why no identification was
     * made, so the caller can phrase a message the person in front of the
     * camera can act on ("move closer" reads very differently from "the service
     * is down").
     *
     * @return array{employee: Employee|null, distance: float|null, runner_up: float|null, reason: string|null}
     */
    public static function identify(string $imagePath): array
    {
        $probe = FaceVerification::embed($imagePath);

        if (($probe['reachable'] ?? true) === false) {
            return self::nobody('service_unavailable');
        }

        if (($probe['liveness'] ?? 'unknown') === 'spoof') {
            return self::nobody('spoof');
        }

        if (! ($probe['detected'] ?? false) || empty($probe['embedding'])) {
            return self::nobody('not_detected');
        }

        $roster = self::enrolledEmbeddings();

        if ($roster === []) {
            return self::nobody('no_enrolled_faces');
        }

        [$bestId, $bestDistance, $runnerUpDistance] = self::rank($probe['embedding'], $roster);

        if ($bestDistance > self::threshold()) {
            return self::nobody('no_match');
        }

        // A runner-up that is nearly as close means the roster cannot tell the
        // two apart today. Refusing is the safe answer: a wrongly attributed
        // check-in is far more work to unpick than a second attempt.
        if ($runnerUpDistance !== null && ($runnerUpDistance - $bestDistance) < self::margin()) {
            return self::nobody('ambiguous');
        }

        return [
            'employee' => Employee::find($bestId),
            'distance' => $bestDistance,
            'runner_up' => $runnerUpDistance,
            'reason' => null,
        ];
    }

    /**
     * Drop the cached roster. Called on employee writes; also useful in tests.
     */
    public static function forgetEnrolledEmbeddings(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Every enrolled employee's reference embedding, keyed by employee id.
     *
     * Cached because a shift change sends the whole office through one terminal
     * inside a few minutes, and each scan would otherwise re-read and re-decode
     * one JSON column per employee.
     *
     * @return array<int, list<float>>
     */
    private static function enrolledEmbeddings(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Employee::query()
            ->whereNotNull('face_embedding')
            ->pluck('face_embedding', 'id')
            ->reject(fn ($embedding) => empty($embedding))
            ->all());
    }

    /**
     * Nearest and second-nearest enrolled employee to the probe.
     *
     * @param  list<float>  $probe
     * @param  array<int, list<float>>  $roster
     * @return array{0: int|null, 1: float, 2: float|null}
     */
    private static function rank(array $probe, array $roster): array
    {
        $probe = self::normalize($probe);

        $bestId = null;
        $best = INF;
        $runnerUp = null;

        foreach ($roster as $employeeId => $embedding) {
            $distance = 1.0 - self::dot($probe, self::normalize($embedding));

            if ($distance < $best) {
                $runnerUp = $best === INF ? null : $best;
                $best = $distance;
                $bestId = (int) $employeeId;

                continue;
            }

            if ($runnerUp === null || $distance < $runnerUp) {
                $runnerUp = $distance;
            }
        }

        return [$bestId, $best, $runnerUp];
    }

    /**
     * Cosine distance is 1 - the dot product of two unit vectors, matching the
     * Python service exactly (see FaceEngine::verify). InsightFace already
     * returns L2-normalised embeddings; normalising again is a cheap guard
     * against a stored vector that was not, and a no-op when it was.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private static function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn (float $value) => $value * $value, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $value) => $value / $magnitude, $vector);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        // Mismatched dimensions mean a stored embedding from another model
        // version; treat it as maximally distant rather than crashing a scan.
        if (count($a) !== count($b)) {
            return -1.0;
        }

        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * $b[$index];
        }

        return $sum;
    }

    /**
     * @return array{employee: null, distance: null, runner_up: null, reason: string}
     */
    private static function nobody(string $reason): array
    {
        return ['employee' => null, 'distance' => null, 'runner_up' => null, 'reason' => $reason];
    }

    private static function threshold(): float
    {
        return (float) config('attendance.kiosk.identify_threshold');
    }

    private static function margin(): float
    {
        return (float) config('attendance.kiosk.identify_margin');
    }
}
