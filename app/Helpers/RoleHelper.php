<?php

/**
 * RoleHelper
 *
 * Legacy compatibility helper kept in place while the application moves to the
 * new database-backed group system.
 *
 * Responsibilities:
 * - Resolve group names/descriptions from the app_groups table
 * - Provide simple authentication + group guard methods used throughout the app
 * - Refresh session group / permission state on protected requests
 */
class RoleHelper
{
    /**
     * Per-request login validation cache for the current authenticated user.
     *
     * @var int|null
     */
    private static ?int $validatedUserId = null;

    /**
     * Cached group lookups by id.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private static array $groupByIdCache = [];

    /**
     * Cached group lookups by name.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private static array $groupByNameCache = [];

    /**
     * Get the group name by ID.
     *
     * @param int $id Group ID from app_groups
     * @return string|null
     */
    public static function getRoleNameById(int $id): ?string
    {
        if ($id < 1)
        {
            return null;
        }

        $group = self::getGroupById($id);
        return $group['name'] ?? null;
    }

    /**
     * Get the group description by ID.
     *
     * @param int $id Group ID from app_groups
     * @return string|null
     */
    public static function getRoleDescriptionById(int $id): ?string
    {
        if ($id < 1)
        {
            return null;
        }

        $group = self::getGroupById($id);
        return $group['description'] ?? null;
    }

    /**
     * Get the group name by its display name.
     *
     * @param string $name
     * @return string|null
     */
    public static function getRoleNameByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '')
        {
            return null;
        }

        $group = self::getGroupByName($name);
        return $group['name'] ?? null;
    }

    /**
     * Get the group description by display name.
     *
     * @param string $name
     * @return string|null
     */
    public static function getRoleDescriptionByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '')
        {
            return null;
        }

        $group = self::getGroupByName($name);
        return $group['description'] ?? null;
    }

    /**
     * Fetch one group row by id with per-request caching.
     *
     * @param int $id Group ID from app_groups
     * @return array<string, mixed>|null
     */
    private static function getGroupById(int $id): ?array
    {
        if ($id < 1)
        {
            return null;
        }

        if (!array_key_exists($id, self::$groupByIdCache))
        {
            self::$groupByIdCache[$id] = Database::fetch(
                "SELECT id, name, description FROM app_groups WHERE id = :id LIMIT 1",
                ['id' => $id]
            );
        }

        return self::$groupByIdCache[$id];
    }

    /**
     * Fetch one group row by display name with per-request caching.
     *
     * @param string $name
     * @return array<string, mixed>|null
     */
    private static function getGroupByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '')
        {
            return null;
        }

        if (!array_key_exists($name, self::$groupByNameCache))
        {
            self::$groupByNameCache[$name] = Database::fetch(
                "SELECT id, name, description FROM app_groups WHERE name = :name LIMIT 1",
                ['name' => $name]
            );
        }

        return self::$groupByNameCache[$name];
    }

    /**
     * Require that the current user is logged in and active.
     *
     * @return void
     */
    public static function requireLogin(): void
    {
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            RedirectHelper::rememberLoginDestination();
            header('Location: /user/login');
            exit();
        }

        if (self::$validatedUserId === $userId)
        {
            return;
        }

        $user = Database::fetch(
            "SELECT u.status, g.name AS group_name, g.slug AS group_slug
             FROM app_users u
             LEFT JOIN app_groups g ON u.group_id = g.id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $userId]
        );

        $status = strtolower(TypeHelper::toString($user['status'] ?? '', allowEmpty: true) ?? '');
        $groupSlug = strtolower(TypeHelper::toString($user['group_slug'] ?? '', allowEmpty: true) ?? '');

        if ($status !== 'active' || $groupSlug === 'banned')
        {
            RedirectHelper::rememberLoginDestination();
            SessionManager::destroy();
            header('Location: /user/login');
            exit();
        }

        GroupPermissionHelper::syncSessionForUser($userId);
        RulesHelper::enforceBlockingRedirectIfNeeded($userId);
        self::$validatedUserId = $userId;
    }

    /**
     * Require that the current user has one of the given group names or slugs.
     *
     * @param array $allowedRoles
     * @param TemplateEngine|null $template
     * @return void
     */
    public static function requireRole(array $allowedRoles, ?TemplateEngine $template = null): void
    {
        self::requireLogin();

        $userGroup = strtolower(TypeHelper::toString(SessionManager::get('user_group'), allowEmpty: true) ?? '');
        $userGroupSlug = strtolower(TypeHelper::toString(SessionManager::get('user_group_slug'), allowEmpty: true) ?? '');
        $normalizedAllowed = [];

        foreach ($allowedRoles as $role)
        {
            $value = strtolower(TypeHelper::toString($role, allowEmpty: true) ?? '');
            if ($value !== '')
            {
                $normalizedAllowed[] = $value;
            }
        }

        if (!in_array($userGroup, $normalizedAllowed, true) && !in_array($userGroupSlug, $normalizedAllowed, true))
        {
            http_response_code(403);

            $template = $template ?: new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, AppConfig::get());
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Your account does not have access to this area.');
            $template->assign('status_code', 403);
            $template->assign('status_label', 'Forbidden');
            $template->assign('link', RedirectHelper::getSafeRefererPath());
            $template->render('errors/error_page.html');
            exit();
        }
    }
}
