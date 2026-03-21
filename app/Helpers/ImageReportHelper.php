<?php

/**
 * ImageReportHelper
 *
 * Shared metadata and normalization helpers for image report categories and
 * status labels used by the public gallery and control panel.
 */
class ImageReportHelper
{
    /**
     * Supported public report categories.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'tagging' => 'Bad Tagging',
            'details' => 'Incorrect Details',
            'copyright' => 'Copyright / Ownership',
            'sensitive' => 'Sensitive / Safety',
            'duplicate' => 'Duplicate / Repost',
            'other' => 'Other',
        ];
    }

    /**
     * Normalize one user-supplied category to a supported value.
     *
     * @param string $category Raw category value.
     * @return string Normalized category key.
     */
    public static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $categories = self::categories();

        if (!array_key_exists($category, $categories))
        {
            return 'other';
        }

        return $category;
    }

    /**
     * Resolve a display-safe label for one category key.
     *
     * @param string $category Category key.
     * @return string Display label.
     */
    public static function categoryLabel(string $category): string
    {
        $category = self::normalizeCategory($category);
        $categories = self::categories();

        return $categories[$category] ?? 'Other';
    }

    /**
     * Normalize a report status string.
     *
     * @param string $status Raw status value.
     * @return string Normalized status.
     */
    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['open', 'closed'], true) ? $status : 'open';
    }

    /**
     * Resolve the public-facing badge label shown on gallery image pages.
     *
     * @return string Display label.
     */
    public static function publicReviewLabel(): string
    {
        return 'Under Review';
    }

    /**
     * Resolve the staff workflow label for one report.
     *
     * @param string $status Raw database status value.
     * @param int $assignedUserId Assigned staff user id.
     * @return string Display label.
     */
    public static function workflowStatusLabel(string $status, int $assignedUserId = 0): string
    {
        $status = self::normalizeStatus($status);

        if ($status === 'closed')
        {
            return 'Closed';
        }

        if ($assignedUserId > 0)
        {
            return 'Assigned';
        }

        return 'Open';
    }

    /**
     * Resolve one control-panel CSS modifier for the report workflow pill.
     *
     * @param string $status Raw database status value.
     * @param int $assignedUserId Assigned staff user id.
     * @return string CSS class suffix.
     */
    public static function workflowStatusClass(string $status, int $assignedUserId = 0): string
    {
        $status = self::normalizeStatus($status);

        if ($status === 'closed')
        {
            return 'closed';
        }

        if ($assignedUserId > 0)
        {
            return 'assigned';
        }

        return 'open';
    }
}
