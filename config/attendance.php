<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GPS Integrity Thresholds
    |--------------------------------------------------------------------------
    |
    | Controls anti-spoofing validation on submitted GPS data.
    | Raise these values in development to loosen restrictions.
    |
    */

    'gps' => [
        'max_accuracy_meters' => (float) env('GPS_MAX_ACCURACY_METERS', 150),
        'max_age_seconds' => (int) env('GPS_MAX_AGE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Face Recognition
    |--------------------------------------------------------------------------
    |
    | Attendance verification against an enrolled reference embedding. The
    | microservice runs CPU-only and is reached over the internal compose
    | network. Disable with FACE_RECOGNITION_ENABLED=false to fall back to
    | GPS-only attendance (e.g. while the service is on maintenance).
    |
    | require_enrollment: when true, an employee with no enrolled face cannot
    |   check in. Set to false only if face capture is a soft gate.
    |
    */

    'face' => [
        'enabled' => (bool) env('FACE_RECOGNITION_ENABLED', true),
        'service_url' => env('FACE_SERVICE_URL', 'http://face-recognition:5000'),
        'threshold' => (float) env('FACE_DISTANCE_THRESHOLD', 0.5),
        'require_enrollment' => (bool) env('FACE_REQUIRE_ENROLLMENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kiosk Terminal
    |--------------------------------------------------------------------------
    |
    | The unattended terminal identifies an employee from their face alone
    | (1:N) instead of confirming an already-authenticated one (1:1).
    |
    | identify_threshold: maximum cosine distance for a match. Stricter than
    |   face.threshold on purpose — a 1:N search has as many chances to be
    |   wrong as there are enrolled employees, so the bar has to be higher.
    | identify_margin: how much closer the winner must be than the runner-up.
    |   Below this the two candidates are treated as indistinguishable and the
    |   scan is refused rather than guessed.
    |
    */

    'kiosk' => [
        'identify_threshold' => (float) env('KIOSK_IDENTIFY_THRESHOLD', 0.38),
        'identify_margin' => (float) env('KIOSK_IDENTIFY_MARGIN', 0.06),
        'scan_ttl_seconds' => (int) env('KIOSK_SCAN_TTL_SECONDS', 90),
    ],

];
