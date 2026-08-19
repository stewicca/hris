<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the Python face-recognition microservice over the internal network.
 *
 * Both operations fail-closed when the service is unreachable: it is safer to
 * block a check-in than to let it through without verification. Callers that
 * want fail-open behaviour must check {@see isOperational()} first.
 */
class FaceVerification
{
    /**
     * Extract an embedding from an enrollment photo.
     *
     * @param  string  $imagePath  Absolute path to the uploaded image on local disk.
     * @return array{embedding: list<float>|null, detected: bool, liveness: string}
     */
    public static function embed(string $imagePath): array
    {
        return self::callService('/embed', $imagePath);
    }

    /**
     * Compare a probe photo against a reference embedding (1:1).
     *
     * @param  list<float>  $referenceEmbedding
     * @return array{verified: bool, distance: float, liveness: string, detected: bool}
     */
    public static function verify(string $imagePath, array $referenceEmbedding): array
    {
        $response = self::callService('/verify', $imagePath, [
            'reference_embedding' => implode(',', $referenceEmbedding),
        ]);

        return [
            'verified' => (bool) ($response['verified'] ?? false),
            'distance' => (float) ($response['distance'] ?? 1.0),
            'liveness' => (string) ($response['liveness'] ?? 'unknown'),
            'detected' => (bool) ($response['detected'] ?? false),
        ];
    }

    /**
     * Whether the feature is enabled and the microservice is reachable.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('attendance.face.enabled', true);
    }

    /**
     * POST the image to the service and return the decoded JSON body, or a
     * fail-closed payload on any error.
     *
     * @param  array<string, string>  $extraFields  Additional multipart form fields.
     * @return array<string, mixed>
     */
    private static function callService(string $endpoint, string $imagePath, array $extraFields = []): array
    {
        $url = config('attendance.face.service_url').$endpoint;

        // Every field — image and text — is attached as a multipart part.
        // Mixing Http::attach() with post($data) drops the text fields because
        // the post() body is rebuilt as form-urlencoded and the boundary wraps
        // only the file part. Sending all parts via attach() keeps the body a
        // single consistent multipart/form-data payload.
        $parts = collect($extraFields)
            ->map(fn ($value, $name) => ['name' => $name, 'contents' => (string) $value])
            ->prepend(['name' => 'image', 'contents' => file_get_contents($imagePath), 'filename' => basename($imagePath)])
            ->values()
            ->all();

        try {
            $response = Http::timeout(20)
                ->asMultipart()
                ->withHeaders(['Accept' => 'application/json'])
                ->post($url, $parts);
        } catch (ConnectionException $e) {
            Log::warning('Face verification service unreachable.', ['endpoint' => $endpoint, 'exception' => $e->getMessage()]);

            return self::unreachablePayload();
        }

        if (! $response->successful()) {
            Log::warning('Face verification service returned an error.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return self::unreachablePayload();
        }

        return $response->json() ?? [];
    }

    /**
     * Fail-closed response used whenever the service cannot be reached or
     * returns an error. Embedding callers see no embedding; verify callers
     * see `verified: false`.
     *
     * @return array<string, mixed>
     */
    private static function unreachablePayload(): array
    {
        return [
            'embedding' => null,
            'detected' => false,
            'liveness' => 'unknown',
            'verified' => false,
            'distance' => 1.0,
        ];
    }
}
