<?php

/**
 * DateHelper
 *
 * A utility class for handling date formatting and comparisons.
 * All dates are assumed to be stored in UTC (e.g., database),
 * and are converted into the application timezone before being formatted.
 *
 * Features:
 * - Initialize application timezone.
 * - Format dates into human-readable strings.
 * - Format birthdays separately with a simplified format.
 * - Automatically display relative dates:
 *     * Today
 *     * Yesterday
 *     * X day(s) ago
 *     * 1 week ago
 *     * 2 weeks ago
 *     * Fallback to normal format for anything older
 */
class DateHelper
{
    private static ?\DateTimeZone $appTimezone = null;

    /**
     * Initializes the application timezone.
     *
     * @param string $timezone Timezone identifier (e.g., "America/Chicago").
     */
    public static function init(string $timezone): void
    {
        self::$appTimezone = new DateTimeZone($timezone);
    }

    /**
     * Formats a given UTC date into the application's timezone.
     *
     * Returns relative strings for recent dates, otherwise falls back
     * to the normal formatted date string.
     *
     * @param string|null $date   Input date string in UTC (usually from DB).
     * @param string      $format Output format if not recent.
     *
     * @return string
     */
    public static function format(?string $date, string $format = "F jS, Y \\a\\t g:i A"): string
    {
        if ($date === null)
        {
            return '';
        }

        // Convert UTC date into application timezone
        $utc = new DateTimeZone('UTC');
        $datetime = new DateTime($date, $utc);
        $datetime->setTimezone(self::$appTimezone);

        // Reference points in app timezone
        $today = new DateTime('today', self::$appTimezone);
        $yesterday = (clone $today)->modify('-1 day');

        // Check if date is today
        if ($datetime >= $today)
        {
            return 'Today';
        }

        // Check if date is yesterday
        if ($datetime >= $yesterday && $datetime < $today)
        {
            return 'Yesterday';
        }

        // Calculate absolute difference in days from today
        $diffDays = (int)$datetime->diff($today)->days;

        // 2–6 days ago
        if ($diffDays >= 2 && $diffDays <= 6)
        {
            return $diffDays . ' days ago';
        }

        // 7–13 days → 1 week ago
        if ($diffDays >= 7 && $diffDays <= 13)
        {
            return '1 week ago';
        }

        // 14–20 days → 2 weeks ago
        if ($diffDays >= 14 && $diffDays <= 20)
        {
            return '2 weeks ago';
        }

        // Older than 20 days → fallback to default formatting
        return $datetime->format($format) ?? '';
    }

    /**
     * Formats a birthday date into the application's timezone.
     *
     * If the birthday is not set, returns a placeholder string.
     *
     * @param string|null $date   Input date string.
     * @param string      $format Output format.
     *
     * @return string
     */
    public static function birthday_format(?string $date, string $format = "F jS, Y"): string
    {
        if (empty($date))
        {
            return 'Not set yet';
        }

        $datetime = new DateTime($date);
        $datetime->setTimezone(self::$appTimezone);

        return $datetime->format($format) ?? '';
    }
}
