<?php

/**
 * GroupPermissionHelper
 *
 * Centralized helper for group-aware permission checks and session syncing.
 *
 * Responsibilities:
 * - Define the built-in permission catalog used by the ACP
 * - Load group + permission state for the current authenticated user
 * - Expose simple permission guard helpers for controllers/templates
 * - Keep session data aligned with database-backed group changes
 */
class GroupPermissionHelper
{
    /**
     * Per-request sync cache to avoid reloading the same authenticated user state
     * multiple times in one request.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $sessionSyncCache = [];

    /**
     * Cross-request session sync cache window in seconds.
     */
    private const SESSION_SYNC_TTL = 120;

    /**
     * Return the permission catalog exposed by the ACP group editor.
     *
     * @return array<string, array<string, string>>
     */
    public static function getPermissionCatalog(): array
    {
        return [
            'view_gallery' => [
                'label' => 'Can View Gallery',
                'description' => 'Allows the group to view gallery listings and image pages.',
                'input_type' => 'select',
            ],
            'upload_images' => [
                'label' => 'Can Upload Images',
                'description' => 'Allows new image uploads through the gallery upload page.',
                'input_type' => 'select',
            ],
            'comment_images' => [
                'label' => 'Can Comment On Images',
                'description' => 'Allows posting comments on gallery images.',
                'input_type' => 'select',
            ],
            'report_images' => [
                'label' => 'Can Report Images',
                'description' => 'Allows submitting image moderation reports.',
                'input_type' => 'select',
            ],
            'vote_images' => [
                'label' => 'Can Vote On Images',
                'description' => 'Allows upvoting or otherwise voting on images.',
                'input_type' => 'select',
            ],
            'favorite_images' => [
                'label' => 'Can Favorite Images',
                'description' => 'Allows saving images to personal favorites.',
                'input_type' => 'select',
            ],
            'edit_own_image' => [
                'label' => 'Can Edit Own Images',
                'description' => 'Allows users to edit metadata on their own images.',
                'input_type' => 'select',
            ],
            'edit_any_image' => [
                'label' => 'Can Edit Any Image',
                'description' => 'Allows staff to edit metadata on any image record.',
                'input_type' => 'select',
            ],
            'access_control_panel' => [
                'label' => 'Can Access Control Panel',
                'description' => 'Allows access to the shared staff control panel shell.',
                'input_type' => 'select',
            ],
            'manage_users' => [
                'label' => 'Can Manage Users',
                'description' => 'Allows reviewing, creating, and editing account records.',
                'input_type' => 'select',
            ],
            'manage_groups' => [
                'label' => 'Can Manage Groups',
                'description' => 'Allows creating and editing group definitions and registration defaults.',
                'input_type' => 'select',
            ],
            'manage_group_permissions' => [
                'label' => 'Can Manage Group Permissions',
                'description' => 'Allows editing permission tokens assigned to groups.',
                'input_type' => 'select',
            ],
            'manage_settings' => [
                'label' => 'Can Manage Settings',
                'description' => 'Allows editing site and application settings through the ACP.',
                'input_type' => 'select',
            ],
            'manage_rules' => [
                'label' => 'Can Manage Rules',
                'description' => 'Allows editing rules categories, rule entries, and publishing rules updates.',
                'input_type' => 'select',
            ],
            'view_security' => [
                'label' => 'Can View Security Logs',
                'description' => 'Allows reviewing security logs and audit details.',
                'input_type' => 'select',
            ],
            'manage_block_list' => [
                'label' => 'Can Manage Block List',
                'description' => 'Allows creating, editing, and removing enforcement block entries.',
                'input_type' => 'select',
            ],
            'moderate_site' => [
                'label' => 'Can Moderate Site',
                'description' => 'Site-wide moderation umbrella permission for staff workflows.',
                'input_type' => 'select',
            ],
            'moderate_forums' => [
                'label' => 'Can Moderate Forums',
                'description' => 'Reserved for forum moderation tools and workflows.',
                'input_type' => 'select',
            ],
            'moderate_gallery' => [
                'label' => 'Can Moderate Gallery',
                'description' => 'Allows gallery moderation access and image review workflows.',
                'input_type' => 'select',
            ],
            'moderate_image_queue' => [
                'label' => 'Can Moderate Image Queue',
                'description' => 'Allows approving or rejecting pending uploaded images.',
                'input_type' => 'select',
            ],
            'manage_image_reports' => [
                'label' => 'Can Manage Image Reports',
                'description' => 'Allows viewing, assigning, reopening, and closing image reports.',
                'input_type' => 'select',
            ],
            'compare_images' => [
                'label' => 'Can Compare Images',
                'description' => 'Allows access to the image hash comparison tool.',
                'input_type' => 'select',
            ],
            'rehash_images' => [
                'label' => 'Can Rehash Images',
                'description' => 'Allows recalculating image perceptual hashes from the ACP.',
                'input_type' => 'select',
            ],
        ];
    }

    /**
     * Return the default built-in permission values by group slug.
     *
     * @param string $slug
     * @return array<string, int>
     */
    public static function getDefaultPermissionsForGroup(string $slug): array
    {
        $catalog = array_fill_keys(array_keys(self::getPermissionCatalog()), 0);
        $slug = strtolower(trim($slug));

        switch ($slug)
        {
            case 'site-administrator':
                foreach ($catalog as $token => $value)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'administrator':
                foreach ([
                    'view_gallery',
                    'upload_images',
                    'comment_images',
                    'report_images',
                    'vote_images',
                    'favorite_images',
                    'edit_own_image',
                    'edit_any_image',
                    'access_control_panel',
                    'manage_users',
                    'manage_settings',
                    'manage_rules',
                    'view_security',
                    'manage_block_list',
                    'moderate_site',
                    'moderate_forums',
                    'moderate_gallery',
                    'moderate_image_queue',
                    'manage_image_reports',
                    'compare_images',
                    'rehash_images',
                ] as $token)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'site-moderator':
                foreach ([
                    'view_gallery',
                    'upload_images',
                    'comment_images',
                    'report_images',
                    'vote_images',
                    'favorite_images',
                    'edit_own_image',
                    'edit_any_image',
                    'access_control_panel',
                    'moderate_site',
                    'moderate_forums',
                    'moderate_gallery',
                    'moderate_image_queue',
                    'manage_image_reports',
                    'compare_images',
                ] as $token)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'forum-moderator':
                foreach ([
                    'view_gallery',
                    'comment_images',
                    'report_images',
                    'vote_images',
                    'favorite_images',
                    'edit_own_image',
                    'access_control_panel',
                    'moderate_forums',
                ] as $token)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'image-moderator':
                foreach ([
                    'view_gallery',
                    'upload_images',
                    'comment_images',
                    'report_images',
                    'vote_images',
                    'favorite_images',
                    'edit_own_image',
                    'edit_any_image',
                    'access_control_panel',
                    'moderate_gallery',
                    'moderate_image_queue',
                    'manage_image_reports',
                    'compare_images',
                ] as $token)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'member':
                foreach ([
                    'view_gallery',
                    'upload_images',
                    'comment_images',
                    'report_images',
                    'vote_images',
                    'favorite_images',
                    'edit_own_image',
                ] as $token)
                {
                    $catalog[$token] = 1;
                }
                break;

            case 'banned':
            default:
                break;
        }

        return $catalog;
    }

    /**
     * Sync the authenticated session with current group + permission state.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public static function syncSessionForUser(int $userId, bool $force = false): array
    {
        $payload = [
            'group_id' => 0,
            'group_name' => '',
            'group_slug' => '',
            'permissions' => [],
        ];

        if (!$force && isset(self::$sessionSyncCache[$userId]))
        {
            return self::$sessionSyncCache[$userId];
        }

        if ($userId < 1)
        {
            self::clearSessionPermissionState();
            self::$sessionSyncCache[$userId] = $payload;
            return $payload;
        }

        $syncedAt = TypeHelper::toInt(SessionManager::get('user_permissions_synced_at')) ?? 0;
        $sessionPermissions = SessionManager::get('user_permissions', []);
        $sessionGroupId = TypeHelper::toInt(SessionManager::get('user_group_id')) ?? 0;
        $sessionGroupName = TypeHelper::toString(SessionManager::get('user_group'), allowEmpty: true) ?? '';
        $sessionGroupSlug = strtolower(TypeHelper::toString(SessionManager::get('user_group_slug'), allowEmpty: true) ?? '');
        $isFreshSessionState = !$force
            && $syncedAt > 0
            && (time() - $syncedAt) < self::SESSION_SYNC_TTL
            && $sessionGroupId > 0
            && is_array($sessionPermissions)
            && (TypeHelper::toInt(SessionManager::get('user_id')) ?? 0) === $userId;

        if ($isFreshSessionState)
        {
            $payload = [
                'group_id' => $sessionGroupId,
                'group_name' => $sessionGroupName,
                'group_slug' => $sessionGroupSlug,
                'permissions' => $sessionPermissions,
            ];

            self::$sessionSyncCache[$userId] = $payload;
            return $payload;
        }

        $row = Database::fetch(
            "SELECT u.group_id, g.name AS group_name, g.slug AS group_slug
             FROM app_users u
             LEFT JOIN app_groups g ON u.group_id = g.id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $userId]
        ) ?? [];

        $groupId = TypeHelper::toInt($row['group_id'] ?? 0) ?? 0;
        $groupName = TypeHelper::toString($row['group_name'] ?? '', allowEmpty: true) ?? '';
        $groupSlug = strtolower(TypeHelper::toString($row['group_slug'] ?? '', allowEmpty: true) ?? '');
        $permissions = $groupId > 0 ? GroupModel::getPermissionMapByGroupId($groupId) : [];

        $payload = [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'group_slug' => $groupSlug,
            'permissions' => $permissions,
        ];

        SessionManager::setMany([
            'user_group_id' => $groupId,
            'user_group' => $groupName,
            'user_role' => $groupName,
            'user_group_slug' => $groupSlug,
            'user_permissions' => $permissions,
            'user_can_access_panel' => !empty($permissions['access_control_panel']) ? 1 : 0,
            'user_permissions_synced_at' => time(),
        ]);

        self::$sessionSyncCache[$userId] = $payload;

        return $payload;
    }

    /**
     * Remove group-specific session values.
     *
     * @return void
     */
    public static function clearSessionPermissionState(): void
    {
        self::$sessionSyncCache = [];

        SessionManager::setMany([
            'user_group_id' => 0,
            'user_group' => '',
            'user_role' => '',
            'user_group_slug' => '',
            'user_permissions' => [],
            'user_can_access_panel' => 0,
            'user_permissions_synced_at' => 0,
        ]);
    }

    /**
     * Check whether the current session has one permission token.
     *
     * @param string $token
     * @return bool
     */
    public static function hasPermission(string $token): bool
    {
        $token = trim($token);
        if ($token === '')
        {
            return false;
        }

        $permissions = SessionManager::get('user_permissions', []);
        if (!is_array($permissions))
        {
            $permissions = [];
        }

        return !empty($permissions[$token]);
    }

    /**
     * Check whether the current session has any permission from the list.
     *
     * @param array $tokens
     * @return bool
     */
    public static function hasAnyPermission(array $tokens): bool
    {
        foreach ($tokens as $token)
        {
            $token = TypeHelper::toString($token, allowEmpty: true) ?? '';
            if ($token !== '' && self::hasPermission($token))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the current authenticated user belongs to one of the given group slugs.
     *
     * @param array $slugs
     * @return bool
     */
    public static function hasGroupSlug(array $slugs): bool
    {
        $current = strtolower(TypeHelper::toString(SessionManager::get('user_group_slug'), allowEmpty: true) ?? '');
        if ($current === '')
        {
            return false;
        }

        $normalized = [];
        foreach ($slugs as $slug)
        {
            $value = strtolower(TypeHelper::toString($slug, allowEmpty: true) ?? '');
            if ($value !== '')
            {
                $normalized[] = $value;
            }
        }

        return in_array($current, $normalized, true);
    }

    /**
     * Determine whether the current request expects a JSON response.
     *
     * @return bool
     */
    private static function wantsJsonResponse(): bool
    {
        $accept = strtolower(TypeHelper::toString($_SERVER['HTTP_ACCEPT'] ?? '', allowEmpty: true) ?? '');
        $requestedWith = strtolower(TypeHelper::toString($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', allowEmpty: true) ?? '');

        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    /**
     * Build a lightweight template instance for access-denied responses.
     *
     * @return TemplateEngine
     */
    private static function initDenyTemplate(): TemplateEngine
    {
        $config = AppConfig::get();
        $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);

        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        return $template;
    }

    /**
     * Resolve a user-facing deny message for one permission token.
     *
     * @param string $token
     * @return string
     */
    private static function getPermissionDeniedMessage(string $token): string
    {
        $token = trim($token);

        $map = [
            'view_gallery' => 'Your account group cannot view the gallery.',
            'upload_images' => 'Your account group cannot upload images.',
            'comment_images' => 'Your account group cannot comment on images.',
            'report_images' => 'Your account group cannot report images.',
            'vote_images' => 'Your account group cannot like or vote on images.',
            'favorite_images' => 'Your account group cannot favorite images.',
            'edit_own_image' => 'Your account group cannot edit this image.',
            'edit_any_image' => 'Your account group cannot edit this image.',
            'access_control_panel' => 'Your account group cannot access the control panel.',
            'manage_users' => 'Your account group cannot manage user accounts.',
            'manage_groups' => 'Your account group cannot manage groups.',
            'manage_group_permissions' => 'Your account group cannot manage group permissions.',
            'manage_settings' => 'Your account group cannot manage site settings.',
            'manage_rules' => 'Your account group cannot manage site rules.',
            'view_security' => 'Your account group cannot view security information.',
            'manage_block_list' => 'Your account group cannot manage the block list.',
            'moderate_site' => 'Your account group cannot access site moderation tools.',
            'moderate_forums' => 'Your account group cannot access forum moderation tools.',
            'moderate_gallery' => 'Your account group cannot access gallery moderation tools.',
            'moderate_image_queue' => 'Your account group cannot moderate the image queue.',
            'manage_image_reports' => 'Your account group cannot manage image reports.',
            'compare_images' => 'Your account group cannot access image comparison tools.',
            'rehash_images' => 'Your account group cannot rehash images.',
        ];

        return $map[$token] ?? 'You do not have permission to access this area.';
    }

    /**
     * Resolve a safe back link for access-denied pages.
     *
     * @return string|null
     */
    private static function resolveDeniedLink(): ?string
    {
        $candidates = [
            RedirectHelper::sanitizeInternalPath($_POST['back_to'] ?? null, true),
            RedirectHelper::sanitizeInternalPath($_GET['back_to'] ?? null, true),
            RedirectHelper::getSafeRefererPath(),
        ];

        foreach ($candidates as $candidate)
        {
            if (!empty($candidate))
            {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Render a consistent 403 response for permission-denied requests.
     *
     * @param string $message
     * @param TemplateEngine|null $template
     * @return void
     */
    private static function renderPermissionDenied(string $message, ?TemplateEngine $template = null): void
    {
        if (self::wantsJsonResponse())
        {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => false,
                'message' => $message,
                'status' => 403,
            ], JSON_UNESCAPED_SLASHES);
            exit();
        }

        http_response_code(403);

        $template = $template ?: self::initDenyTemplate();
        $template->assign('title', 'Access Denied');
        $template->assign('message', $message);
        $template->assign('status_code', 403);
        $template->assign('status_label', 'Forbidden');
        $template->assign('link', self::resolveDeniedLink());
        $template->render('errors/error_page.html');
        exit();
    }

    /**
     * Require one permission token.
     *
     * @param string $token
     * @param TemplateEngine|null $template
     * @return void
     */
    public static function requirePermission(string $token, ?TemplateEngine $template = null): void
    {
        RoleHelper::requireLogin();

        if (!self::hasPermission($token))
        {
            self::renderPermissionDenied(self::getPermissionDeniedMessage($token), $template);
        }
    }

    /**
     * Require at least one permission from the provided list.
     *
     * @param array $tokens
     * @param TemplateEngine|null $template
     * @return void
     */
    public static function requireAnyPermission(array $tokens, ?TemplateEngine $template = null): void
    {
        RoleHelper::requireLogin();

        if (!self::hasAnyPermission($tokens))
        {
            self::renderPermissionDenied('Your account group does not have permission to access this area.', $template);
        }
    }
}
