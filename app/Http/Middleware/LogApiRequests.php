<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogApiRequests
{
    /**
     * Maximum number of bytes persisted for request and response bodies.
     */
    private const int MAX_BODY_BYTES = 65535;

    private const string REDACTED = '[redacted]';

    /**
     * Input keys never written to the log. Credentials must not be
     * recoverable from an api_logs row or from a database dump of it.
     *
     * @var list<string>
     */
    private const array SENSITIVE_INPUT = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
        'remember_token',
        'secret',
        'code',
        'recovery_code',
        'reference_embedding',
    ];

    /**
     * Headers never written to the log. These carry session cookies and
     * bearer tokens, which are as good as a password to an attacker.
     *
     * @var list<string>
     */
    private const array SENSITIVE_HEADERS = [
        'cookie',
        'set-cookie',
        'authorization',
        'proxy-authorization',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        $startedAt = microtime(true);

        $requestSnapshot = [
            'method' => $request->method(),
            'path' => $request->path(),
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'request_headers' => $this->redactHeaders($request->headers->all()),
            'query_params' => $this->redactInput($request->query()) ?: null,
            'request_body' => $this->captureRequestBody($request),
        ];

        $response = $next($request);

        $this->persist($requestSnapshot, $response, $startedAt);

        return $response;
    }

    /**
     * Capture the parsed input rather than the raw body.
     *
     * Reading the raw body would persist plaintext passwords on /login and
     * /password, and would store tens of kilobytes of binary JPEG on every
     * attendance check-in. Parsed input lets sensitive keys be redacted and
     * uploads be reduced to a one-line descriptor.
     */
    private function captureRequestBody(Request $request): ?string
    {
        $input = $this->redactInput($request->all());

        if ($input === []) {
            return null;
        }

        $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return null;
        }

        return $this->truncate($encoded);
    }

    /**
     * Replace sensitive values and summarise uploaded files.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function redactInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_INPUT, true)) {
                $input[$key] = self::REDACTED;

                continue;
            }

            if ($value instanceof UploadedFile) {
                $input[$key] = sprintf(
                    '[file: %s, %s bytes, %s]',
                    $value->getClientOriginalName(),
                    $value->getSize() ?? 0,
                    $value->getClientMimeType(),
                );

                continue;
            }

            if (is_array($value)) {
                $input[$key] = $this->redactInput($value);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                $headers[$name] = self::REDACTED;
            }
        }

        return $headers;
    }

    /**
     * Response bodies are only kept for failures.
     *
     * A successful response body is reconstructable from the request and adds
     * nothing to an audit trail, while doubling the write amplification of
     * every request — which is what turns a request flood into a full disk.
     */
    private function captureResponseBody(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $content = $response->getContent();

        if ($content === '' || $content === false) {
            return null;
        }

        return $this->truncate($content);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_BODY_BYTES) {
            return $value;
        }

        // Keep the result within MAX_BODY_BYTES (the TEXT column limit) by
        // reserving room for the suffix, otherwise the insert overflows on MySQL.
        $suffix = '...[truncated]';

        return substr($value, 0, self::MAX_BODY_BYTES - strlen($suffix)).$suffix;
    }

    /**
     * @param  array<string, mixed>  $requestSnapshot
     */
    private function persist(array $requestSnapshot, Response $response, float $startedAt): void
    {
        try {
            ApiLog::create([
                ...$requestSnapshot,
                'status_code' => $response->getStatusCode(),
                'response_headers' => $this->redactHeaders($response->headers->all()),
                'response_body' => $this->captureResponseBody($response),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to persist API log', ['exception' => $e->getMessage()]);
        }
    }
}
