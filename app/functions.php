<?php

/**
 * Here is your custom functions.
 */

use support\Log;

if (!function_exists('casiel_log')) {
    /**
     * Helper function for standardized logging.
     *
     * @param string $channel The log channel to use.
     * @param string $message The log message.
     * @param array $context Optional context data.
     * @param string $level The log level (debug, info, warning, error).
     * @return void
     */
    function casiel_log(string $channel, string $message, array $context = [], string $level = 'info'): void
    {
        try {
            // Ensure the channel exists in the config, otherwise fallback to default
            $channelToUse = config('log.' . $channel) ? $channel : 'default';
            Log::channel($channelToUse)->{$level}($message, $context);
        } catch (Throwable $e) {
            // Fallback to default logger if something goes wrong
            Log::error("Logging failed for channel '$channel': " . $e->getMessage());
            Log::error($message, $context);
        }
    }
}
