<?php

namespace MahmoudMhamed\BackupStation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * File-based throttle middleware.
 *
 * Uses a JSON file under sys_get_temp_dir() instead of the Laravel cache,
 * so the package keeps working even when the user's cache driver is broken
 * (e.g. CACHE_STORE=database pointing at a missing table during a restore
 * onto an empty database).
 *
 * Signature: bs.throttle:<max-attempts>,<decay-minutes>
 *   e.g. bs.throttle:5,1   ->  5 attempts per 1 minute per IP
 */
class BackupStationThrottle
{
    public function handle(Request $request, Closure $next, int|string $maxAttempts = 60, int|string $decayMinutes = 1): Response
    {
        $maxAttempts = max(1, (int) $maxAttempts);
        $decaySeconds = max(1, (int) $decayMinutes) * 60;

        $key = $this->resolveKey($request);
        $file = $this->throttleFile($key);

        [$attempts, $expiresAt] = $this->load($file);

        $now = time();
        if ($expiresAt <= $now) {
            $attempts = 0;
            $expiresAt = $now + $decaySeconds;
        }

        if ($attempts >= $maxAttempts) {
            $retryAfter = max(1, $expiresAt - $now);
            return response('Too Many Requests', 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $expiresAt,
            ]);
        }

        $attempts++;
        $this->save($file, $attempts, $expiresAt);

        $response = $next($request);

        // Best-effort attach headers (Symfony Response).
        if (method_exists($response, 'headers')) {
            $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $attempts));
            $response->headers->set('X-RateLimit-Reset', (string) $expiresAt);
        }

        return $response;
    }

    protected function resolveKey(Request $request): string
    {
        $route = $request->route()?->getName() ?? $request->path();
        return sha1(($request->ip() ?? 'unknown') . '|' . $route);
    }

    protected function throttleFile(string $key): string
    {
        $dir = sys_get_temp_dir() . '/backup-station/throttle';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . $key . '.json';
    }

    /** @return array{0:int,1:int}  [attempts, expires_at] */
    protected function load(string $file): array
    {
        if (!is_file($file)) return [0, 0];

        $raw = @file_get_contents($file);
        if ($raw === false) return [0, 0];

        $data = json_decode($raw, true);
        if (!is_array($data)) return [0, 0];

        return [(int) ($data['attempts'] ?? 0), (int) ($data['expires_at'] ?? 0)];
    }

    protected function save(string $file, int $attempts, int $expiresAt): void
    {
        @file_put_contents(
            $file,
            json_encode(['attempts' => $attempts, 'expires_at' => $expiresAt]),
            LOCK_EX
        );
    }
}
