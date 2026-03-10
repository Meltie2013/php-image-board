<?php

/**
 * NumericalHelper
 *
 * Provides helper methods for formatting numeric values into a more compact,
 * human-readable representation. This is useful for displaying large counts
 * such as votes, likes, views, downloads, or similar metrics in a clean UI.
 *
 * Example conversions:
 * - 950       => "950"
 * - 1,234     => "1.2k"
 * - 15,500    => "15.5k"
 * - 2,300,000 => "2.3m"
 */
class NumericalHelper
{
    /**
     * Convert a raw integer into a compact display string.
     *
     * This method shortens large numbers using common suffixes:
     * - k = thousand
     * - m = million
     * - b = billion
     * - t = trillion
     *
     * Behavior:
     * - Numbers below 1000 are returned unchanged as strings
     * - Numbers 1000 and above are scaled down to the most appropriate unit
     * - Values under 10 in a shortened unit are displayed with one decimal place
     *   for better readability (example: 1.2k)
     * - Values 10 or greater in a shortened unit are rounded to whole numbers
     *   (example: 15k)
     * - Trailing ".0" is removed so output stays clean
     *
     * @param int $number The raw numeric value to format
     * @return string The formatted human-readable value
     */
    public static function formatCount(int $number): string
    {
        // For any number smaller than 1000, no compact formatting is needed.
        // Return the value exactly as a string so small counts remain precise.
        if ($number < 1000)
        {
            return (string)$number;
        }

        // Define the suffixes used for each magnitude.
        // Index positions correspond to powers of 1000:
        // 0 = no suffix
        // 1 = thousand
        // 2 = million
        // 3 = billion
        // 4 = trillion
        $units = ['', 'k', 'm', 'b', 't'];

        // Determine which unit scale should be used by calculating the
        // logarithmic "power" of the number in base 1000.
        //
        // Examples:
        // - 1,234       => 1  (thousands)
        // - 2,300,000   => 2  (millions)
        // - 4,500,000,000 => 3 (billions)
        $power = (int)floor(log($number, 1000));

        // Scale the number down to the selected unit range.
        //
        // Example:
        // - 1,234 / 1000^1 = 1.234
        // - 2,300,000 / 1000^2 = 2.3
        $value = $number / pow(1000, $power);

        // Format the scaled value for display.
        //
        // If the scaled number is below 10 and uses a unit suffix, keep one
        // decimal place for improved readability:
        // - 1.2k instead of 1k
        //
        // Otherwise, round to a whole number:
        // - 15k instead of 15.5k if formatting rules call for no decimals
        //
        // The ($power > 0) check ensures decimal formatting is only applied
        // when a compact suffix is actually being used.
        $formatted = number_format($value, ($value < 10 && $power > 0) ? 1 : 0);

        // Remove any unnecessary trailing zeroes and decimal points so values
        // like "1.0" become "1" before the suffix is appended.
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        // Combine the cleaned numeric value with its corresponding suffix and
        // return the final compact representation.
        return $formatted . $units[$power];
    }
}
