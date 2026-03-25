<?php

/**
 * RulesHelper
 *
 * Centralizes rules acceptance state, grace-period notices, and forced redirect
 * handling for users who must accept the current rules release.
 */
class RulesHelper
{
    /**
     * Per-request current-user rules state cache.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $stateCache = [];

    /**
     * Return the current rules acceptance state for one authenticated user.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public static function getCurrentStateForUser(int $userId): array
    {
        if ($userId < 1)
        {
            return self::getDefaultState();
        }

        if (isset(self::$stateCache[$userId]))
        {
            return self::$stateCache[$userId];
        }

        $state = self::getDefaultState();
        if (!RulesModel::isSchemaAvailable())
        {
            self::$stateCache[$userId] = $state;
            return $state;
        }

        $release = RulesModel::findCurrentRelease();
        if (!$release)
        {
            self::$stateCache[$userId] = $state;
            return $state;
        }

        $acceptance = RulesModel::ensureCurrentReleaseAcceptanceForUser($userId);
        $status = strtolower(TypeHelper::toString($acceptance['status'] ?? '', allowEmpty: true) ?? '');
        $accepted = $status === 'accepted';
        $enforceAfter = TypeHelper::toString($acceptance['enforce_after'] ?? '', allowEmpty: true) ?? '';
        $enforceTimestamp = $enforceAfter !== '' ? strtotime($enforceAfter) : 0;
        $blocking = !$accepted && ($enforceTimestamp <= 0 || $enforceTimestamp <= time());
        $hasPending = !$accepted;
        $deadlineDisplay = $enforceTimestamp > 0 ? DateHelper::date_only_format($enforceAfter, 'F j, Y \a\t g:i a') : '';

        $title = 'Rules Updated';
        $message = '';
        if ($hasPending && $blocking)
        {
            $message = 'The latest site rules must be accepted before you can continue using your account.';
        }
        else if ($hasPending)
        {
            $message = 'Please review and accept them before ' . $deadlineDisplay . ' to avoid a temporary account block.';
        }

        $state = [
            'has_release' => 1,
            'release_id' => TypeHelper::toInt($release['id'] ?? 0) ?? 0,
            'release_label' => TypeHelper::toString($release['version_label'] ?? '', allowEmpty: true) ?? '',
            'release_summary' => TypeHelper::toString($release['summary'] ?? '', allowEmpty: true) ?? '',
            'release_published_at' => TypeHelper::toString($release['published_at'] ?? '', allowEmpty: true) ?? '',
            'release_published_display' => DateHelper::date_only_format(TypeHelper::toString($release['published_at'] ?? '', allowEmpty: true), 'F j, Y \a\t g:i a'),
            'accepted' => $accepted ? 1 : 0,
            'has_pending' => $hasPending ? 1 : 0,
            'is_blocking' => $blocking ? 1 : 0,
            'deadline_at' => $enforceAfter,
            'deadline_display' => $deadlineDisplay,
            'notice_title' => $title,
            'notice_message' => $message,
            'notice_link' => '/community/rules',
        ];

        self::$stateCache[$userId] = $state;
        return $state;
    }

    /**
     * Return the default no-release/no-pending state.
     *
     * @return array<string, mixed>
     */
    private static function getDefaultState(): array
    {
        return [
            'has_release' => 0,
            'release_id' => 0,
            'release_label' => '',
            'release_summary' => '',
            'release_published_at' => '',
            'release_published_display' => '',
            'accepted' => 1,
            'has_pending' => 0,
            'is_blocking' => 0,
            'deadline_at' => '',
            'deadline_display' => '',
            'notice_title' => '',
            'notice_message' => '',
            'notice_link' => '/community/rules',
        ];
    }

    /**
     * Determine whether the current request path is exempt from forced rules
     * acceptance redirects.
     *
     * @param string $path
     * @return bool
     */
    public static function isExemptPath(string $path): bool
    {
        $path = strtolower(trim($path));
        if ($path === '')
        {
            $path = '/';
        }

        if ($path === '/community/rules'
            || $path === '/community/rules/accept'
            || $path === '/rules'
            || $path === '/rules/accept'
            || $path === '/community/notifications'
            || str_starts_with($path, '/community/notifications/page/')
            || $path === '/notifications'
            || $path === '/user/logout')
        {
            return true;
        }

        return false;
    }

    /**
     * Redirect the current user to the rules page when the latest rules release
     * must be accepted immediately.
     *
     * @param int $userId
     * @return void
     */
    public static function enforceBlockingRedirectIfNeeded(int $userId): void
    {
        if ($userId < 1)
        {
            return;
        }

        $path = RedirectHelper::getCurrentRequestPath();
        if (self::isExemptPath($path))
        {
            return;
        }

        $state = self::getCurrentStateForUser($userId);
        if (empty($state['is_blocking']))
        {
            return;
        }

        RedirectHelper::rememberLoginDestination(RedirectHelper::getCurrentRequestUri());
        header('Location: /community/rules');
        exit();
    }
}
