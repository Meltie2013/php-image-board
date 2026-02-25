<?php

/**
 * Centralized security utilities for the application.
 *
 * Responsibilities:
 * - CSRF token generation/verification for form submissions
 * - Input validation/sanitization helpers for common types
 * - Output escaping helpers to reduce XSS risk
 * - Password hashing/verification with configurable algorithms and options
 *
 * This class is intentionally static to provide a consistent, low-friction
 * API across controllers and views. Call Security::init() during bootstrap
 * so configuration (token name, password options, etc.) is available.
 */
class Security
{
    // Security configuration loaded from config.php (token names, password options, etc.)
    private static array $config = [];

    /**
     * Initialize Security settings from application configuration.
     *
     * Stores security-related options so the helper methods can remain
     * stateless and reusable throughout the request lifecycle.
     *
     * @param array $config Security config from config.php
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    // =====================
    // CSRF Protection
    // =====================

    /**
     * Generate a CSRF token and store it in the session.
     *
     * If a token already exists for this session, it is returned as-is so
     * the token remains stable across multiple forms rendered in one session.
     *
     * @return string CSRF token value safe to embed into a form
     */
    public static function generateCsrfToken(): string
    {
        $tokenName = self::$config['security']['csrf_token_name'] ?? '_csrf';

        // Reuse the existing token when present to avoid rotating unexpectedly
        $existing = SessionManager::get($tokenName);
        if ($existing)
        {
            return $existing;
        }

        // Use cryptographically secure randomness for unpredictable tokens
        $token = bin2hex(random_bytes(32));
        SessionManager::set($tokenName, $token);

        return $token;
    }

    /**
     * Verify a CSRF token from the request against the session token.
     *
     * Uses a constant-time comparison to avoid leaking information through timing
     * differences when comparing attacker-controlled input.
     *
     * @param string|null $token CSRF token received from the request (POST/headers)
     * @return bool True when the token matches the session value; otherwise false
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        $tokenName = self::$config['security']['csrf_token_name'] ?? '_csrf';
        $sessionToken = SessionManager::get($tokenName);

        // Reject missing/empty tokens immediately
        if (!$sessionToken || !$token)
        {
            return false;
        }

        // Constant-time comparison to reduce timing attack surface
        return hash_equals($sessionToken, $token);
    }

    // =====================
    // Input Sanitization
    // =====================

    /**
     * Sanitize free-form string input for safe storage and/or display.
     *
     * This helper:
     * - Trims leading/trailing whitespace
     * - Removes HTML tags
     * - Encodes special characters to reduce XSS risk when echoed back
     *
     * Note: Prefer output escaping at render time (escapeHtml()) as the primary
     * defense. This method is useful for normalizing user text input and
     * reducing accidental markup.
     *
     * @param string $input Raw user input
     * @return string Sanitized string safe for typical application usage
     */
    public static function sanitizeString(string $input): string
    {
        // Remove surrounding whitespace
        $clean = trim($input);

        // Strip any HTML tags
        $clean = strip_tags($clean);

        // Encode special HTML characters to prevent XSS when content is re-rendered
        $clean = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $clean;
    }

    /**
     * Validate and sanitize an email address.
     *
     * Returns null if the input is not a syntactically valid email.
     *
     * @param string $input Raw user input
     * @return string|null Validated email string, or null when invalid
     */
    public static function sanitizeEmail(string $input): ?string
    {
        $filtered = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        return $filtered ?: null;
    }

    /**
     * Validate and sanitize an integer value.
     *
     * Useful for IDs and pagination inputs. Returns null when the value is
     * not a valid integer representation.
     *
     * @param mixed $input Raw user input
     * @return int|null Valid integer value, or null when invalid
     */
    public static function sanitizeInt(mixed $input): ?int
    {
        $filtered = filter_var($input, FILTER_VALIDATE_INT);
        return $filtered !== false ? $filtered : null;
    }

    /**
     * Validate and sanitize a boolean value.
     *
     * Accepts typical boolean representations (true/false, 1/0, "on"/"off", etc.).
     * Uses FILTER_NULL_ON_FAILURE to avoid accidental truthy coercion of invalid
     * values; invalid values are treated as false by default.
     *
     * @param mixed $input Raw user input
     * @return bool True/false normalized boolean value
     */
    public static function sanitizeBool(mixed $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Validate and sanitize a floating point value.
     *
     * Returns null when the value cannot be parsed as a float.
     * (Note: If you need locale-aware parsing, handle that before calling this.)
     * 
     * @param mixed $input Raw user input
     * @return float|null Valid float value, or null when invalid
     */
    public static function sanitizeFloat(mixed $input): ?float
    {
        $filtered = filter_var($input, FILTER_VALIDATE_FLOAT);
        return $filtered !== false ? $filtered : null;
    }

    /**
     * Sanitize / validate a date input against an expected format.
     *
     * Why this is safer than a regex:
     * - Rejects invalid calendar dates (e.g. 2026-02-31)
     * - Rejects values that PHP would otherwise auto-normalize
     * - Optionally enforces min/max bounds
     *
     * @param string $input  Raw user input
     * @param string $format Expected PHP date format (default: Y-m-d)
     * @param string|null $min Minimum allowed date (same format), inclusive
     * @param string|null $max Maximum allowed date (same format), inclusive
     * @param string|null $timezone Timezone for parsing/comparison (default: UTC)
     * @return string|null Normalized date string in $format, or null if invalid
     */
    public static function sanitizeDate(string $input, string $format = 'Y-m-d', ?string $min = null, ?string $max = null, ?string $timezone = 'UTC'): ?string
    {
        $input = trim($input);
        if ($input === '')
        {
            return null;
        }

        $tz = new DateTimeZone($timezone ?: 'UTC');

        // '!' resets unspecified fields so comparisons are stable
        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $input, $tz);
        if (!$dt)
        {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (!empty($errors['warning_count']) || !empty($errors['error_count']))
        {
            return null;
        }

        // Prevent "auto-normalized" dates (e.g. 2026-02-31 -> 2026-03-03)
        $normalized = $dt->format($format);
        if ($normalized !== $input)
        {
            return null;
        }

        if ($min !== null)
        {
            $minDt = DateTimeImmutable::createFromFormat('!' . $format, $min, $tz);
            if (!$minDt || $dt < $minDt)
            {
                return null;
            }
        }

        if ($max !== null)
        {
            $maxDt = DateTimeImmutable::createFromFormat('!' . $format, $max, $tz);
            if (!$maxDt || $dt > $maxDt)
            {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * Escape a string for safe HTML output.
     *
     * Use this at render time when outputting user-controlled strings into HTML.
     * This is the preferred XSS defense versus pre-encoding values in storage.
     *
     * @param string $input Raw string to escape
     * @return string HTML-escaped string safe for direct echo in HTML
     */
    public static function escapeHtml(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // =====================
    // Password Hashing
    // =====================

    /**
     * Hash a password securely using the configured algorithm (default: Argon2id).
     *
     * Uses PHP's password_hash() to automatically generate salts and produce
     * strong password hashes. Options (memory_cost, time_cost, threads, etc.)
     * may be provided via configuration.
     *
     * @param string $password Plaintext password to hash
     * @return string Password hash suitable for storage in the database
     */
    public static function hashPassword(string $password): string
    {
        $options = self::$config['security']['password_options'] ?? [];
        return password_hash($password, self::$config['security']['password_algo'] ?? PASSWORD_ARGON2ID, $options);
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * @param string $password Plaintext password provided by the user
     * @param string $hash Stored password hash from the database
     * @return bool True when the password matches the hash; otherwise false
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Determine whether a stored password hash should be re-hashed.
     *
     * Useful after changing hashing algorithms/options to transparently upgrade
     * hashes on the next successful login.
     *
     * @param string $hash Stored password hash
     * @return bool True if the hash should be regenerated; otherwise false
     */
    public static function needsRehash(string $hash): bool
    {
        $options = self::$config['security']['password_options'] ?? [];
        return password_needs_rehash($hash, self::$config['security']['password_algo'] ?? PASSWORD_ARGON2ID, $options);
    }
}
