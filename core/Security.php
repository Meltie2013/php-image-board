<?php

class Security
{
    // Configuration array
    private static array $config = [];

    /**
     * Initialize Security settings
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
     * Generate a CSRF token and store in session
     *
     * @return string
     */
    public static function generateCsrfToken(): string
    {
        $tokenName = self::$config['security']['csrf_token_name'] ?? '_csrf';

        // Return existing token if already in session
        $existing = SessionManager::get($tokenName);
        if ($existing)
        {
            return $existing;
        }

        // Generate new token if not found
        $token = bin2hex(random_bytes(32));
        SessionManager::set($tokenName, $token);

        return $token;
    }

    /**
     * Verify CSRF token from request
     *
     * @param string|null $token
     * @return bool
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        $tokenName = self::$config['security']['csrf_token_name'] ?? '_csrf';
        $sessionToken = SessionManager::get($tokenName);

        if (!$sessionToken || !$token)
        {
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($sessionToken, $token);
    }

    // =====================
    // Input Sanitization
    // =====================

    /**
     * Sanitize string input (letters, numbers, spaces, basic punctuation)
     *
     * @param string $input
     * @return string
     */
    public static function sanitizeString(string $input): string
    {
        // Remove surrounding whitespace
        $clean = trim($input);

        // Strip any HTML tags
        $clean = strip_tags($clean);

        // Encode special HTML characters to prevent XSS
        $clean = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $clean;
    }

    /**
     * Sanitize integer input
     *
     * @param mixed $input
     * @return int|null
     */
    public static function sanitizeInt(mixed $input): ?int
    {
        $filtered = filter_var($input, FILTER_VALIDATE_INT);
        return $filtered !== false ? $filtered : null;
    }

    /**
     * Sanitize email input
     *
     * @param string $input
     * @return string|null
     */
    public static function sanitizeEmail(string $input): ?string
    {
        $filtered = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        return $filtered ?: null;
    }

    /**
     * Sanitize boolean input
     *
     * @param mixed $input
     * @return bool
     */
    public static function sanitizeBool(mixed $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Escape HTML for output
     *
     * @param string $input
     * @return string
     */
    public static function escapeHtml(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // =====================
    // Password Hashing
    // =====================

    /**
     * Hash a password securely using Argon2id
     *
     * @param string $password
     * @return string
     */
    public static function hashPassword(string $password): string
    {
        $options = self::$config['security']['password_options'] ?? [];
        return password_hash($password, self::$config['security']['password_algo'] ?? PASSWORD_ARGON2ID, $options);
    }

    /**
     * Verify password against hash
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehash (for future algorithm upgrades)
     *
     * @param string $hash
     * @return bool
     */
    public static function needsRehash(string $hash): bool
    {
        $options = self::$config['security']['password_options'] ?? [];
        return password_needs_rehash($hash, self::$config['security']['password_algo'] ?? PASSWORD_ARGON2ID, $options);
    }
}
