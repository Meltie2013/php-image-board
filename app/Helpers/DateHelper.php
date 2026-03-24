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
 *     * X second(s) ago
 *     * X minute(s) ago
 *     * X hour(s) ago
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
     * Ensures the application timezone has been initialized.
     *
     * If init() was not called, fall back to PHP's default timezone.
     *
     * @return \DateTimeZone
     */
    private static function getTimezone(): \DateTimeZone
    {
        if (self::$appTimezone instanceof \DateTimeZone)
        {
            return self::$appTimezone;
        }

        try
        {
            self::$appTimezone = new DateTimeZone(date_default_timezone_get());
        }
        catch (\Exception $e)
        {
            self::$appTimezone = new DateTimeZone('UTC');
        }

        return self::$appTimezone;
    }

    /**
     * Initializes the application timezone.
     *
     * @param string $timezone Timezone identifier (e.g., "America/Chicago").
     */
    public static function init(string $timezone): void
    {
        self::$appTimezone = new DateTimeZone($timezone);

        // Keep PHP's global default timezone in sync for any code using date() / DateTime defaults.
        date_default_timezone_set($timezone);
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
    public static function format(?string $date, string $format = "F jS, Y \\a\\t g:i a"): string
    {
        if ($date === null)
        {
            return '';
        }

        // Convert UTC date into application timezone
        $utc = new DateTimeZone('UTC');
        $datetime = new DateTime($date, $utc);
        $datetime->setTimezone(self::getTimezone());

        // Use start-of-day for both the current day and the target date to avoid partial-day issues
        $todayStart = new DateTime('today', self::getTimezone());
        $dateStart = (clone $datetime)->setTime(0, 0, 0);

        // Calculate absolute difference in whole days between today and the date
        $diffDays = (int)$todayStart->diff($dateStart)->format('%a');

        // Same calendar day
        if ($diffDays === 0)
        {
            $now = new DateTime('now', self::getTimezone());

            $diffSeconds = (int)abs($now->getTimestamp() - $datetime->getTimestamp());

            // Seconds (under 1 minute)
            if ($diffSeconds < 60)
            {
                return $diffSeconds . ' second' . ($diffSeconds === 1 ? '' : 's') . ' ago';
            }

            // Minutes (under 1 hour)
            if ($diffSeconds < 3600)
            {
                $minutes = (int)floor($diffSeconds / 60);
                return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
            }

            // Hours (under 1 day)
            if ($diffSeconds < 86400)
            {
                $hours = (int)floor($diffSeconds / 3600);
                return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
            }
        }

        // Exactly 1 day difference
        if ($diffDays === 1)
        {
            return 'Yesterday';
        }

        // 2–6 days ago
        if ($diffDays >= 2 && $diffDays <= 6)
        {
            return $diffDays . ' days ago';
        }

        // Weeks calculation (floor of days / 7)
        $weeks = (int)floor($diffDays / 7);

        // 1–2 weeks ago (keep cutoff at 2 weeks)
        if ($weeks >= 1 && $weeks <= 2)
        {
            return $weeks === 1 ? '1 week ago' : '2 weeks ago';
        }

        // Older than 2 weeks → fall back to normal formatting
        return $datetime->format($format) ?? '';
    }

    /**
     * Formats a given UTC date into the application's timezone.
     *
     * Shows date format without seconds, minutes and hours etc.
     *
     * @param string|null $date   Input date string in UTC (usually from DB).
     * @param string      $format Output format if not recent.
     *
     * @return string
     */
    public static function date_only_format(?string $date, string $format = "F jS, Y \\a\\t g:i a"): string
    {
        if ($date === null)
        {
            return '';
        }

        // Convert UTC date into application timezone
        $utc = new DateTimeZone('UTC');
        $datetime = new DateTime($date, $utc);
        $datetime->setTimezone(self::getTimezone());

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

        return $datetime->format($format) ?? '';
    }

    /**
     * Determine whether one stored birthday matches the current day.
     *
     * @param string|null $date Input birthday date string.
     * @return bool
     */
    public static function isBirthdayToday(?string $date): bool
    {
        if (empty($date))
        {
            return false;
        }

        try
        {
            $datetime = new DateTime($date, self::getTimezone());
            $datetime->setTimezone(self::getTimezone());
        }
        catch (Exception $e)
        {
            return false;
        }

        $today = new DateTime('now', self::getTimezone());

        return $datetime->format('m-d') === $today->format('m-d');
    }
}
