<?php

namespace app\services;

use DateTime;
use DateTimeZone;
use support\Redis;
use Throwable;

/**
 * Manages daily API usage quotas for different services.
 * It uses Redis for persistent, fast counters and handles timezone-aware resets.
 */
class QuotaService
{
    private string $serviceName;
    private string $timezone;
    private int $limit;
    private string $resetTime;

    /**
     * @param string $serviceName The name of the service to manage (e.g., 'gemini'). Used to fetch .env variables.
     */
    public function __construct(string $serviceName)
    {
        $this->serviceName = strtolower($serviceName);
        $this->limit = (int)getenv(strtoupper($this->serviceName) . '_DAILY_LIMIT') ?: 1500;
        $this->timezone = getenv(strtoupper($this->serviceName) . '_QUOTA_RESET_TIMEZONE') ?: 'America/Caracas';
        $this->resetTime = getenv(strtoupper($this->serviceName) . '_QUOTA_RESET_TIME') ?: '03:00';
    }

    /**
     * Calculates the Redis key for the current quota period.
     * The key is date-stamped based on the configured reset time and timezone.
     * For example, if reset is at 03:00, any time before that belongs to the previous day's quota.
     *
     * @return string The Redis key for today's quota.
     */
    private function getRedisKey(): string
    {
        try {
            $now = new DateTime('now', new DateTimeZone($this->timezone));

            // If the current time is before the reset time, we are still in the previous day's quota window.
            if ($now->format('H:i') < $this->resetTime) {
                $now->modify('-1 day');
            }
            $dateSuffix = $now->format('Y-m-d');
            return "quota:{$this->serviceName}:{$dateSuffix}";
        } catch (Throwable $e) {
            casiel_log('quota_service', 'Error creating DateTime object for quota key. Falling back to UTC date.', ['error' => $e->getMessage()], 'critical');
            return "quota:{$this->serviceName}:" . date('Y-m-d');
        }
    }

    /**
     * Checks if a request is allowed based on the current usage count.
     *
     * @return bool True if the request is allowed, false if the quota has been exceeded.
     */
    public function isAllowed(): bool
    {
        // A limit of 0 or less means the quota is disabled.
        if ($this->limit <= 0) {
            return true;
        }

        try {
            $key = $this->getRedisKey();
            $currentCount = (int)Redis::get($key);

            if ($currentCount >= $this->limit) {
                casiel_log('quota_service', "Límite de peticiones a {$this->serviceName} alcanzado.", ['count' => $currentCount, 'limit' => $this->limit], 'warning');
                return false;
            }
            return true;
        } catch (Throwable $e) {
            casiel_log('quota_service', "No se pudo verificar la cuota en Redis para {$this->serviceName}. Permitiendo la petición por defecto.", ['error' => $e->getMessage()], 'error');
            return true; // Fail-open strategy
        }
    }

    /**
     * Records a new usage event by incrementing the counter in Redis.
     */
    public function recordUsage(): void
    {
        // A limit of 0 or less means the quota is disabled, so we don't record anything.
        if ($this->limit <= 0) {
            return;
        }

        try {
            $key = $this->getRedisKey();
            $newCount = Redis::incr($key);

            // If this is the first increment for this key, set an expiry.
            // This prevents the key from living forever if the reset logic ever fails.
            // 25 hours is a safe TTL to cover the entire day plus the reset window overlap.
            if ($newCount == 1) {
                Redis::expire($key, 25 * 3600);
            }
        } catch (Throwable $e) {
            casiel_log('quota_service', "No se pudo registrar el uso para {$this->serviceName} en Redis.", ['error' => $e->getMessage()], 'error');
        }
    }
}
