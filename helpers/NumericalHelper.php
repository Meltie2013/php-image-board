<?php

class NumericalHelper
{
    /**
     * Convert a number into a compact, human-readable format.
     *
     * Examples:
     *   - 950       => "950"
     *   - 1,234     => "1.2k"
     *   - 15,500    => "15.5k"
     *   - 2,300,000 => "2.3M"
     *
     * @param int $number The raw vote count
     * @return string Formatted count
     */
    public static function formatCount(int $number): string
    {
        // If the number is less than 1000, just return it as-is
        if ($number < 1000)
        {
            return (string)$number;
        }

        // Suffixes for different scales (thousands, millions, billions, trillions)
        $units = ['', 'k', 'M', 'B', 'T'];

        // Determine the "power" of 1000 (e.g., 1 = thousand, 2 = million)
        $power = (int)floor(log($number, 1000));

        // Divide the number down to the scale (e.g., 1234 -> 1.234k)
        $value = $number / pow(1000, $power);

        // Format with one decimal if < 10 (like 1.2k), otherwise no decimals
        // The check ($power > 0) ensures we only add decimals for k/M/B/T values
        $formatted = number_format($value, ($value < 10 && $power > 0) ? 1 : 0);

        // Remove unnecessary trailing ".0"
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        // Append the unit (k, M, B, T)
        return $formatted . $units[$power];
    }
}
