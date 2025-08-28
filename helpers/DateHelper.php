<?php

class DateHelper
{
    private static ?\DateTimeZone $appTimezone = null;

    public static function init(string $timezone): void
    {
        self::$appTimezone = new DateTimeZone($timezone);
    }

    public static function format(?string $date, string $format = "F jS, Y \\a\\t g:i A"): string
    {
        if ($date === null)
        {
            return '';
        }

        $utc = new DateTimeZone('UTC');
        $datetime = new DateTime($date, $utc);
        $datetime->setTimezone(self::$appTimezone);

        return $datetime->format($format) ?? '';
    }

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
