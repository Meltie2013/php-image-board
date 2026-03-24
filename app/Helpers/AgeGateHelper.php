<?php

/**
 * AgeGateHelper
 *
 * Centralizes content-rating, age-gate, and birthday-badge decisions.
 */
class AgeGateHelper
{
    /**
     * Normalize one image content rating while preserving legacy compatibility.
     *
     * @param string|null $contentRating
     * @param int|null $ageSensitive
     * @return string
     */
    public static function normalizeContentRating(?string $contentRating, ?int $ageSensitive = null): string
    {
        $rating = strtolower(trim(TypeHelper::toString($contentRating ?? '', allowEmpty: true) ?? ''));
        if (in_array($rating, ['standard', 'sensitive', 'explicit'], true))
        {
            return $rating;
        }

        return (TypeHelper::toInt($ageSensitive ?? 0) ?? 0) === 1 ? 'sensitive' : 'standard';
    }

    /**
     * Determine whether the board age gate is enabled.
     *
     * @param array $config
     * @return bool
     */
    public static function isEnabled(array $config): bool
    {
        return !isset($config['profile']['age_gate_enabled']) || !empty($config['profile']['age_gate_enabled']);
    }

    /**
     * Determine whether self-serve sensitive-content access is enabled.
     *
     * @param array $config
     * @return bool
     */
    public static function isSelfServeEnabled(array $config): bool
    {
        return !isset($config['profile']['self_serve_age_gate']) || !empty($config['profile']['self_serve_age_gate']);
    }

    /**
     * Get the minimum age required for sensitive content.
     *
     * @param array $config
     * @return int
     */
    public static function getSensitiveYears(array $config): int
    {
        $years = TypeHelper::toInt($config['profile']['years'] ?? 13) ?? 13;

        return max(0, $years);
    }

    /**
     * Get the minimum age required for explicit content.
     *
     * @param array $config
     * @return int
     */
    public static function getExplicitYears(array $config): int
    {
        $sensitiveYears = self::getSensitiveYears($config);
        $years = TypeHelper::toInt($config['profile']['explicit_years'] ?? max(18, $sensitiveYears)) ?? max(18, $sensitiveYears);

        return max($sensitiveYears, $years);
    }

    /**
     * Calculate the earliest allowed birth date for one age requirement.
     *
     * @param int $years
     * @return string
     */
    public static function calculateMinimumBirthDate(int $years): string
    {
        $years = max(0, $years);

        return (new DateTimeImmutable('now'))->modify('-' . $years . ' years')->format('Y-m-d');
    }

    /**
     * Check whether one date of birth meets the provided age requirement.
     *
     * @param string|null $dateOfBirth
     * @param int $years
     * @return bool
     */
    public static function isDateOfBirthOldEnough(?string $dateOfBirth, int $years): bool
    {
        $dob = TypeHelper::toString($dateOfBirth ?? '', allowEmpty: true) ?? '';
        if ($dob === '')
        {
            return false;
        }

        return $dob <= self::calculateMinimumBirthDate($years);
    }

    /**
     * Get the stored age-gate status for one account.
     *
     * @param array|null $user
     * @return string
     */
    public static function getUserAgeGateStatus(?array $user): string
    {
        $status = strtolower(trim(TypeHelper::toString($user['age_gate_status'] ?? '', allowEmpty: true) ?? ''));
        if (in_array($status, ['not_started', 'self_served', 'forced_review', 'verified', 'restricted_minor'], true))
        {
            return $status;
        }

        if (!empty($user['date_of_birth']) && !empty($user['age_verified_at']))
        {
            return 'verified';
        }

        return 'not_started';
    }

    /**
     * Resolve the highest content level this viewer may access.
     *
     * Values:
     * - standard_only
     * - sensitive
     * - explicit
     *
     * @param array|null $user
     * @param array $config
     * @return string
     */
    public static function getViewerContentAccessLevel(?array $user, array $config): string
    {
        if (!self::isEnabled($config))
        {
            return 'explicit';
        }

        if (!$user)
        {
            return 'standard_only';
        }

        $status = self::getUserAgeGateStatus($user);

        if ($status === 'restricted_minor' || $status === 'forced_review' || $status === 'not_started')
        {
            return 'standard_only';
        }

        if ($status === 'self_served')
        {
            return 'sensitive';
        }

        if ($status === 'verified')
        {
            return self::isDateOfBirthOldEnough($user['date_of_birth'] ?? null, self::getExplicitYears($config))
                ? 'explicit'
                : 'sensitive';
        }

        return 'standard_only';
    }

    /**
     * Determine whether the viewer may access one content rating.
     *
     * @param array|null $user
     * @param string $contentRating
     * @param array $config
     * @return bool
     */
    public static function canAccessContentRating(?array $user, string $contentRating, array $config): bool
    {
        $rating = self::normalizeContentRating($contentRating);
        if ($rating === 'standard')
        {
            return true;
        }

        $level = self::getViewerContentAccessLevel($user, $config);

        return match ($level)
        {
            'explicit' => true,
            'sensitive' => $rating !== 'explicit',
            default => false,
        };
    }

    /**
     * Determine whether one rating should use per-user caching.
     *
     * @param string $contentRating
     * @param array $config
     * @return bool
     */
    public static function shouldUsePerUserCache(string $contentRating, array $config): bool
    {
        return self::isEnabled($config) && self::normalizeContentRating($contentRating) !== 'standard';
    }

    /**
     * Get the human-readable label for one content rating.
     *
     * @param string $contentRating
     * @return string
     */
    public static function getContentRatingLabel(string $contentRating): string
    {
        return match (self::normalizeContentRating($contentRating))
        {
            'sensitive' => 'Sensitive',
            'explicit' => 'Explicit',
            default => 'Standard',
        };
    }

    /**
     * Get the gallery pill class for one content rating.
     *
     * @param string $contentRating
     * @return string
     */
    public static function getContentRatingPillClass(string $contentRating): string
    {
        return match (self::normalizeContentRating($contentRating))
        {
            'sensitive' => 'gallery-image-pill gallery-image-pill-warning',
            'explicit' => 'gallery-image-pill gallery-image-pill-danger',
            default => 'gallery-image-pill',
        };
    }

    /**
     * Get a user-facing title/message pair for locked content access.
     *
     * @param array|null $user
     * @param string $contentRating
     * @param array $config
     * @return array{title: string, message: string}
     */
    public static function getLockedContentCopy(?array $user, string $contentRating, array $config): array
    {
        $rating = self::normalizeContentRating($contentRating);
        $status = self::getUserAgeGateStatus($user);
        $sensitiveYears = self::getSensitiveYears($config);
        $explicitYears = self::getExplicitYears($config);

        if (!$user)
        {
            return [
                'title' => 'Content Locked',
                'message' => 'This image is locked behind the board age gate. Please login and complete content access setup before trying to view mature content.',
            ];
        }

        if ($status === 'forced_review')
        {
            return [
                'title' => 'Content Locked',
                'message' => 'This account must complete a staff-requested age review before sensitive or explicit content can be viewed.',
            ];
        }

        if ($status === 'restricted_minor')
        {
            return [
                'title' => 'Content Locked',
                'message' => 'This account is currently restricted from mature content because it does not meet the board minimum age requirement of ' . $sensitiveYears . '+.',
            ];
        }

        if ($rating === 'explicit')
        {
            return [
                'title' => 'Content Locked',
                'message' => 'This image is marked explicit and is only available to accounts that complete date of birth verification for the ' . $explicitYears . '+ access tier.',
            ];
        }

        return [
            'title' => 'Content Locked',
            'message' => 'This image is marked sensitive and is locked until mature-content access is enabled for this account.',
        ];
    }

    /**
     * Get the display label for one profile age-gate status.
     *
     * @param string $status
     * @return string
     */
    public static function getAgeGateStatusLabel(string $status): string
    {
        return match (self::getUserAgeGateStatus(['age_gate_status' => $status]))
        {
            'self_served' => 'Sensitive Access Enabled',
            'forced_review' => 'Staff Review Required',
            'verified' => 'Verified',
            'restricted_minor' => 'Restricted Minor',
            default => 'Not Started',
        };
    }


    /**
     * Get the display label for one age-gate method value.
     *
     * @param string $method
     * @return string
     */
    public static function getAgeGateMethodLabel(string $method): string
    {
        $method = strtolower(trim(TypeHelper::toString($method, allowEmpty: true) ?? 'none'));

        return match ($method)
        {
            'self_serve' => 'Self-Serve Unlock',
            'dob_forced' => 'Staff DOB Review',
            'dob_optional' => 'DOB Verification',
            'admin_restricted' => 'Staff Restriction',
            default => 'None',
        };
    }

    /**
     * Get the profile alert tone for one age-gate status.
     *
     * @param string $status
     * @return string
     */
    public static function getAgeGateStatusTone(string $status): string
    {
        return match (self::getUserAgeGateStatus(['age_gate_status' => $status]))
        {
            'verified', 'self_served' => 'success',
            'forced_review' => 'warning',
            'restricted_minor' => 'danger',
            default => 'info',
        };
    }

    /**
     * Determine whether birthday badges are enabled.
     *
     * @param array $config
     * @return bool
     */
    public static function isBirthdayBadgeEnabled(array $config): bool
    {
        return !isset($config['profile']['birthday_badge_enabled']) || !empty($config['profile']['birthday_badge_enabled']);
    }

    /**
     * Determine whether one date of birth should show the birthday badge today.
     *
     * @param string|null $dateOfBirth
     * @param array $config
     * @return bool
     */
    public static function shouldShowBirthdayBadge(?string $dateOfBirth, array $config): bool
    {
        return self::isBirthdayBadgeEnabled($config) && DateHelper::isBirthdayToday($dateOfBirth);
    }
}
