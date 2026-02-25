<?php

/**
 * Centralized type normalization and enforcement utilities.
 *
 * Responsibilities:
 * - Strict parsing (string -> int/bool/float) with predictable rules
 * - Strict type enforcement (native int/bool/float/string only)
 * - Common backend helpers (DB row existence checks)
 *
 * Philosophy:
 * - Use to*() when you want strict parsing and a nullable return on failure.
 * - Use require*() when invalid input should be treated as a programmer or caller error.
 * - Use require*Strict() when the value must already be the native type (no parsing).
 */
class TypeHelper
{
    // =====================
    // Strict Parsing (nullable)
    // =====================

    /**
     * Parse a strict integer from int or integer-string.
     *
     * Accepts:
     * - int
     * - strings matching /^[+-]?\d+$/
     *
     * Rejects:
     * - floats, exponent/scientific notation, empty strings, arrays, objects
     *
     * Notes:
     * - Uses a round-trip check to avoid overflow surprises where possible.
     * - Accepts leading zeros (e.g. "0001") as valid and normalizes to 1.
     *
     * @param mixed $value Any value
     * @return int|null Parsed int or null when invalid
     */
    public static function toInt(mixed $value): ?int
    {
        if (is_int($value))
        {
            return $value;
        }

        if (!is_string($value))
        {
            return null;
        }

        $value = trim($value);
        if ($value === '')
        {
            return null;
        }

        if (preg_match('/^[+-]?\d+$/', $value) !== 1)
        {
            return null;
        }

        // Prevent overflow by round-tripping
        $int = (int)$value;
        if ((string)$int !== ltrim($value, '+'))
        {
            // Handle cases like "0001" which stringify to "1" (still valid)
            // Normalize and compare numerically in a strict way
            $normalized = ltrim($value, '+');
            $normalized = ltrim($normalized, '0');
            if ($normalized === '' || $normalized === '-')
            {
                $normalized = '0';
            }

            if ((string)$int !== $normalized && (string)$int !== ('-' . ltrim(ltrim($normalized, '-'), '0')))
            {
                return null;
            }
        }

        return $int;
    }

    /**
     * Parse a strict boolean from bool/int(0/1)/common boolean strings.
     *
     * Accepts:
     * - bool
     * - int 0/1
     * - strings: "1","0","true","false","yes","no","on","off" (case-insensitive)
     *
     * Notes:
     * - Any other value is treated as invalid and returns $default.
     * - Use $default = null when you want to detect invalid values.
     *
     * @param mixed $value Any value
     * @param bool|null $default Returned when invalid (null keeps failure)
     * @return bool|null Parsed bool or default when invalid
     */
    public static function toBool(mixed $value, ?bool $default = null): ?bool
    {
        if (is_bool($value))
        {
            return $value;
        }

        if (is_int($value))
        {
            if ($value === 1)
            {
                return true;
            }

            if ($value === 0)
            {
                return false;
            }

            return $default;
        }

        if (!is_string($value))
        {
            return $default;
        }

        $v = strtolower(trim($value));
        if ($v === '')
        {
            return $default;
        }

        return match ($v)
        {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * Parse a strict float from float/int or decimal string (no exponent).
     *
     * Accepts:
     * - float / int
     * - strings matching /^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/
     *
     * Rejects:
     * - exponent/scientific notation, empty strings, arrays, objects
     *
     * Notes:
     * - Designed for predictable parsing; does not accept locale separators.
     * - Scientific notation (e.g. "1e3") is intentionally rejected.
     *
     * @param mixed $value Any value
     * @return float|null Parsed float or null when invalid
     */
    public static function toFloat(mixed $value): ?float
    {
        if (is_float($value))
        {
            return $value;
        }

        if (is_int($value))
        {
            return (float)$value;
        }

        if (!is_string($value))
        {
            return null;
        }

        $value = trim($value);
        if ($value === '')
        {
            return null;
        }

        // Strict float check (no exponent)
        if (preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $value) !== 1)
        {
            return null;
        }

        return (float)$value;
    }

    /**
     * Parse a strict string from string (and optionally Stringable objects).
     *
     * Accepts:
     * - string
     * - objects implementing __toString (only when $allowStringable is true)
     *
     * Rejects:
     * - arrays, non-stringable objects, resources
     * - empty strings (unless $allowEmpty is true)
     *
     * Note: This does not escape or sanitize for HTML output. Use Security::escapeHtml()
     * at render time for output safety.
     *
     * @param mixed $value Any value
     * @param bool $allowEmpty Whether empty strings are allowed (default: false)
     * @param bool $allowStringable Whether objects implementing __toString are allowed (default: false)
     * @return string|null Parsed string or null when invalid
     */
    public static function toString(mixed $value, bool $allowEmpty = false, bool $allowStringable = false): ?string
    {
        if (is_string($value))
        {
            $value = trim($value);
            if ($value === '' && !$allowEmpty)
            {
                return null;
            }

            return $value;
        }

        if ($allowStringable && is_object($value) && method_exists($value, '__toString'))
        {
            $string = trim((string)$value);
            if ($string === '' && !$allowEmpty)
            {
                return null;
            }

            return $string;
        }

        return null;
    }

    /**
     * Normalize "exists" style DB results into a boolean.
     *
     * Common DB wrappers return:
     * - array/object for a found row
     * - false/null for no row
     *
     * Use this instead of boolean parsing helpers for DB fetch results.
     *
     * @param mixed $row DB fetch result
     * @return bool True when row exists; otherwise false
     */
    public static function rowExists(mixed $row): bool
    {
        return ($row !== false && $row !== null);
    }

    // =====================
    // Require (strict parsing)
    // =====================

    /**
     * Require a value to be a strict integer (native int or integer-string).
     *
     * Throws when the value is not int-like by the same rules as toInt().
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return int Parsed integer
     */
    public static function requireInt(mixed $value, string $label = 'value'): int
    {
        $int = self::toInt($value);
        if ($int === null)
        {
            throw new InvalidArgumentException($label . ' must be an integer.');
        }

        return $int;
    }

    /**
     * Require a value to be a strict boolean (native bool, 0/1 int, or supported string).
     *
     * Throws when the value is not bool-like by the same rules as toBool().
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return bool Parsed boolean
     */
    public static function requireBool(mixed $value, string $label = 'value'): bool
    {
        $bool = self::toBool($value, null);
        if ($bool === null)
        {
            throw new InvalidArgumentException($label . ' must be a boolean.');
        }

        return $bool;
    }

    /**
     * Require a value to be a strict float (native float/int or decimal string without exponent).
     *
     * Throws when the value is not float-like by the same rules as toFloat().
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return float Parsed float
     */
    public static function requireFloat(mixed $value, string $label = 'value'): float
    {
        $float = self::toFloat($value);
        if ($float === null)
        {
            throw new InvalidArgumentException($label . ' must be a float.');
        }

        return $float;
    }

    /**
     * Require a value to be a strict string (native string or optional Stringable).
     *
     * Throws when the value is not string-like by the same rules as toString().
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @param bool $allowEmpty Whether empty strings are allowed (default: false)
     * @param bool $allowStringable Whether objects implementing __toString are allowed (default: false)
     * @return string Parsed string
     */
    public static function requireString(mixed $value, string $label = 'value', bool $allowEmpty = false, bool $allowStringable = false): string
    {
        $string = self::toString($value, $allowEmpty, $allowStringable);
        if ($string === null)
        {
            throw new InvalidArgumentException($label . ' must be a valid string.');
        }

        return $string;
    }

    // =====================
    // Require Strict (native type only)
    // =====================

    /**
     * Require a value to already be a native integer (no parsing).
     *
     * Useful for internal variables where you expect the type to be correct.
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return int Native integer
     */
    public static function requireIntStrict(mixed $value, string $label = 'value'): int
    {
        if (!is_int($value))
        {
            throw new InvalidArgumentException($label . ' must be an integer.');
        }

        return $value;
    }

    /**
     * Require a value to already be a native boolean (no parsing).
     *
     * Useful for internal variables where you expect the type to be correct.
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return bool Native boolean
     */
    public static function requireBoolStrict(mixed $value, string $label = 'value'): bool
    {
        if (!is_bool($value))
        {
            throw new InvalidArgumentException($label . ' must be a boolean.');
        }

        return $value;
    }

    /**
     * Require a value to already be a native float (no parsing).
     *
     * Useful for internal variables where you expect the type to be correct.
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @return float Native float
     */
    public static function requireFloatStrict(mixed $value, string $label = 'value'): float
    {
        if (!is_float($value))
        {
            throw new InvalidArgumentException($label . ' must be a float.');
        }

        return $value;
    }

    /**
     * Require a value to already be a native string, optionally allowing Stringable.
     *
     * This is the strict (native-type) equivalent of requireString().
     *
     * Note: Because PHP frequently stores input/session values as strings anyway,
     * this is mainly intended for internal variables where you are asserting type.
     *
     * @param mixed $value Any value
     * @param string $label Name used in exception message
     * @param bool $allowEmpty Whether empty strings are allowed (default: false)
     * @param bool $allowStringable Whether objects implementing __toString are allowed (default: false)
     * @return string Native string
     */
    public static function requireStringStrict(mixed $value, string $label = 'value', bool $allowEmpty = false, bool $allowStringable = false): string
    {
        // Native-only enforcement (string), with optional support for Stringable objects.
        if (is_string($value))
        {
            $v = trim($value);
            if ($v === '' && !$allowEmpty)
            {
                throw new InvalidArgumentException($label . ' must be a non-empty string.');
            }

            return $v;
        }

        if ($allowStringable && is_object($value) && method_exists($value, '__toString'))
        {
            $v = trim((string)$value);
            if ($v === '' && !$allowEmpty)
            {
                throw new InvalidArgumentException($label . ' must be a non-empty string.');
            }

            return $v;
        }

        throw new InvalidArgumentException($label . ' must be a string.');
    }
}
