<?php

/**
 * Timestamp Helper
 *
 * Provides centralized timestamp generation for consistent time handling
 * across the entire application. All timestamps should use these functions
 * to ensure consistency and avoid timezone-related discrepancies.
 *
 * @package     OSPOS
 * @subpackage  Helpers
 */

if (!function_exists('now')) {
    /**
     * Returns the current server time as a formatted string.
     * This is the central function for generating timestamps.
     *
     * @return string Current server time in 'Y-m-d H:i:s' format
     */
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('now_date')) {
    /**
     * Returns the current server date (without time) as a formatted string.
     *
     * @return string Current server date in 'Y-m-d' format
     */
    function now_date(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('now_time')) {
    /**
     * Returns the current server time (without date) as a formatted string.
     *
     * @return string Current server time in 'H:i:s' format
     */
    function now_time(): string
    {
        return date('H:i:s');
    }
}

if (!function_exists('format_timestamp')) {
    /**
     * Formats a timestamp string to the application's date/time format.
     *
     * @param string $timestamp The timestamp to format
     * @param bool $include_time Whether to include time in the output
     * @return string Formatted date/time string
     */
    function format_timestamp(string $timestamp, bool $include_time = true): string
    {
        $config = config(\Config\OSPOS::class)->settings;
        $date_format = $config['dateformat'] ?? 'Y-m-d';

        if ($include_time && !empty($config['date_or_time_format'])) {
            $time_format = $config['timeformat'] ?? 'H:i:s';
            return date($date_format . ' ' . $time_format, strtotime($timestamp));
        }

        return date($date_format, strtotime($timestamp));
    }
}
