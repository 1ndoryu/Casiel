<?php

/**
 * Here is your custom functions.
 */

use support\Log;

// SOLUCIÓN: Se eliminó 'use Throwable;' ya que es una clase global de PHP y no necesita importarse.

if (!function_exists('casiel_log')) {
    /**
     * Helper function for standardized logging into specific channels.
     * This function relies on the channel configuration in `config/log.php`
     * to also push records to the master log.
     *
     * @param string $channel The log channel to use (e.g., 'audio_processor', 'sword_api').
     * @param string $message The log message.
     * @param array $context Optional context data.
     * @param string $level The log level (debug, info, warning, error).
     * @return void
     */
    function casiel_log(string $channel, string $message, array $context = [], string $level = 'info'): void
    {
        try {
            // Check if the level is valid
            if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])) {
                $level = 'info';
            }
            // Log to the specified channel. The channel config should handle master logging.
            Log::channel($channel)->{$level}($message, $context);
        } catch (Throwable $e) {
            // Fallback to default logger if something goes wrong with the channel
            Log::error("Logging to channel '$channel' failed: " . $e->getMessage());
            Log::error($message, $context); // Log the original message to default
        }
    }
}