<?php

/**
 * Group and RBAC data access helpers.
 */
class GroupModel extends BaseModel
{
    /**
     * Per-request permission map cache keyed by group id.
     *
     * @var array<int, array<string, int>>
     */
    private static array $permissionMapCache = [];

    /**
     * Cached group listings for the current request.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $groupListCache = [];

    /**
     * Built-in seed cache file path relative to storage/cache.
     */
    private const BUILT_IN_SEED_CACHE_FILE = 'group_seed_state.php';

    /**
     * Increment this when the built-in group or permission seed changes.
     */
    private const BUILT_IN_SEED_VERSION = '2026-03-24-rbac-v2';

    /**
     * Per-request guard so bootstrap only verifies built-in data once.
     */
    private static bool $builtInDataChecked = false;

    /**
     * Return the built-in group seed catalog.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBuiltInGroups(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Site Administrator',
                'slug' => 'site-administrator',
                'description' => 'Full owner-level access to all ACP modules, groups, and permissions.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 10,
            ],
            [
                'id' => 2,
                'name' => 'Administrator',
                'slug' => 'administrator',
                'description' => 'Administrative access for normal site administrators without owner-only group controls.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 20,
            ],
            [
                'id' => 3,
                'name' => 'Site Moderator',
                'slug' => 'site-moderator',
                'description' => 'Site-wide moderation group covering all moderation areas.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 30,
            ],
            [
                'id' => 4,
                'name' => 'Forum Moderator',
                'slug' => 'forum-moderator',
                'description' => 'Forum-specific moderation group reserved for forum moderation tools.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 40,
            ],
            [
                'id' => 5,
                'name' => 'Image Moderator',
                'slug' => 'image-moderator',
                'description' => 'Gallery-specific moderation group for uploads, reports, and image actions.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 50,
            ],
            [
                'id' => 6,
                'name' => 'Member',
                'slug' => 'member',
                'description' => 'Standard member access for normal user accounts.',
                'is_built_in' => 1,
                'is_assignable' => 1,
                'sort_order' => 60,
            ],
            [
                'id' => 7,
                'name' => 'Banned',
                'slug' => 'banned',
                'description' => 'Reserved non-assignable group used for banned accounts.',
                'is_built_in' => 1,
                'is_assignable' => 0,
                'sort_order' => 70,
            ],
        ];
    }

    /**
     * Ensure the built-in group set and permission rows exist.
     *
     * The expensive seed writes are only performed when the cached seed version
     * is missing or stale. Normal requests return immediately after the first
     * successful verification, which keeps public page loads from repeatedly
     * rewriting the same built-in groups and permission rows.
     *
     * @return void
     */
    public static function ensureBuiltInData(): void
    {
        if (self::$builtInDataChecked)
        {
            return;
        }

        self::$builtInDataChecked = true;

        if (self::isBuiltInSeedCacheFresh())
        {
            return;
        }

        foreach (self::getBuiltInGroups() as $group)
        {
            self::query(
                "INSERT INTO app_groups (`id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`, `created_at`, `updated_at`)
                 VALUES (:id, :name, :slug, :description, :is_built_in, :is_assignable, :sort_order, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `name` = VALUES(`name`),
                    `slug` = VALUES(`slug`),
                    `description` = VALUES(`description`),
                    `is_built_in` = VALUES(`is_built_in`),
                    `is_assignable` = IF(VALUES(`slug`) = 'banned', 0, `is_assignable`),
                    `sort_order` = VALUES(`sort_order`),
                    `updated_at` = NOW()",
                [
                    'id' => $group['id'],
                    'name' => $group['name'],
                    'slug' => $group['slug'],
                    'description' => $group['description'],
                    'is_built_in' => $group['is_built_in'],
                    'is_assignable' => $group['is_assignable'],
                    'sort_order' => $group['sort_order'],
                ]
            );

            self::replacePermissions((int) $group['id'], GroupPermissionHelper::getDefaultPermissionsForGroup((string) $group['slug']), true);
        }

        $defaultGroupId = self::getDefaultRegistrationGroupId();
        if ($defaultGroupId < 1)
        {
            self::setDefaultRegistrationGroupId(6);
        }

        self::writeBuiltInSeedCache();
    }

    /**
     * Determine whether the built-in seed cache is still valid.
     *
     * @return bool
     */
    private static function isBuiltInSeedCacheFresh(): bool
    {
        $path = self::getBuiltInSeedCachePath();
        if (!is_file($path))
        {
            return false;
        }

        $payload = @include $path;
        if (!is_array($payload))
        {
            return false;
        }

        return TypeHelper::toString($payload['version'] ?? '', allowEmpty: true) === self::BUILT_IN_SEED_VERSION;
    }

    /**
     * Write the built-in seed cache marker file.
     *
     * @return void
     */
    private static function writeBuiltInSeedCache(): void
    {
        $path = self::getBuiltInSeedCachePath();
        $dir = dirname($path);

        if (!is_dir($dir))
        {
            @mkdir($dir, 0755, true);
        }

        $payload = var_export([
            'version' => self::BUILT_IN_SEED_VERSION,
            'written_at' => gmdate('Y-m-d H:i:s'),
        ], true);

        @file_put_contents($path, "<?php return " . $payload . "; ");
    }

    /**
     * Return the absolute built-in seed cache path.
     *
     * @return string
     */
    private static function getBuiltInSeedCachePath(): string
    {
        return rtrim(CACHE_PATH, '/') . '/' . self::BUILT_IN_SEED_CACHE_FILE;
    }

    /**
     * Return all group rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listGroups(): array
    {
        if (!isset(self::$groupListCache['all']))
        {
            self::$groupListCache['all'] = self::fetchAll(
                "SELECT `id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`
                 FROM app_groups
                 ORDER BY `sort_order` ASC, `id` ASC"
            );
        }

        return self::$groupListCache['all'];
    }

    /**
     * Return groups suitable for account assignment.
     *
     * @param bool $includeReserved
     * @return array<int, array<string, mixed>>
     */
    public static function listAssignableGroups(bool $includeReserved = false): array
    {
        if ($includeReserved)
        {
            return self::listGroups();
        }

        if (!isset(self::$groupListCache['assignable']))
        {
            self::$groupListCache['assignable'] = self::fetchAll(
                "SELECT `id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`
                 FROM app_groups
                 WHERE `is_assignable` = 1
                 ORDER BY `sort_order` ASC, `id` ASC"
            );
        }

        return self::$groupListCache['assignable'];
    }

    /**
     * Fetch one group row by id.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findGroupById(int $id): ?array
    {
        if ($id < 1)
        {
            return null;
        }

        return self::fetch(
            "SELECT `id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`
             FROM app_groups
             WHERE `id` = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Fetch one group row by slug.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public static function findGroupBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '')
        {
            return null;
        }

        return self::fetch(
            "SELECT `id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`
             FROM app_groups
             WHERE `slug` = :slug
             LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * Build one permission map for a group.
     *
     * @param int $groupId
     * @return array<string, int>
     */
    public static function getPermissionMapByGroupId(int $groupId): array
    {
        if (isset(self::$permissionMapCache[$groupId]))
        {
            return self::$permissionMapCache[$groupId];
        }

        $permissions = array_fill_keys(array_keys(GroupPermissionHelper::getPermissionCatalog()), 0);

        if ($groupId < 1)
        {
            self::$permissionMapCache[$groupId] = $permissions;
            return $permissions;
        }

        $rows = self::fetchAll(
            "SELECT `permission_token`, `permission_value`
             FROM app_group_permissions
             WHERE `group_id` = :group_id",
            ['group_id' => $groupId]
        );

        foreach ($rows as $row)
        {
            $token = TypeHelper::toString($row['permission_token'] ?? '', allowEmpty: true) ?? '';
            if ($token === '' || !array_key_exists($token, $permissions))
            {
                continue;
            }

            $permissions[$token] = (TypeHelper::toInt($row['permission_value'] ?? 0) ?? 0) > 0 ? 1 : 0;
        }

        self::$permissionMapCache[$groupId] = $permissions;

        return $permissions;
    }

    /**
     * Upsert one group definition.
     *
     * @param array<string, mixed> $payload
     * @return int
     */
    public static function saveGroup(array $payload): int
    {
        $id = TypeHelper::toInt($payload['id'] ?? 0) ?? 0;

        if ($id > 0)
        {
            self::query(
                "UPDATE app_groups
                 SET `name` = :name,
                     `description` = :description,
                     `is_assignable` = :is_assignable,
                     `sort_order` = :sort_order,
                     `updated_at` = NOW()
                 WHERE `id` = :id",
                [
                    'id' => $id,
                    'name' => $payload['name'],
                    'description' => $payload['description'],
                    'is_assignable' => $payload['is_assignable'],
                    'sort_order' => $payload['sort_order'],
                ]
            );

            self::flushGroupCaches($id);

            return $id;
        }

        $id = (int) self::insert(
            "INSERT INTO app_groups (`name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`, `created_at`, `updated_at`)
             VALUES (:name, :slug, :description, 0, :is_assignable, :sort_order, NOW(), NOW())",
            [
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'description' => $payload['description'],
                'is_assignable' => $payload['is_assignable'],
                'sort_order' => $payload['sort_order'],
            ]
        );

        self::flushGroupCaches($id);

        return $id;
    }

    /**
     * Replace all permission rows for one group.
     *
     * @param int $groupId
     * @param array<string, int> $permissions
     * @param bool $preserveBuiltIn Whether built-in sync should preserve custom edits by filling only missing rows.
     * @return void
     */
    public static function replacePermissions(int $groupId, array $permissions, bool $preserveBuiltIn = false): void
    {
        if ($groupId < 1)
        {
            return;
        }

        $catalog = GroupPermissionHelper::getPermissionCatalog();

        if (!$preserveBuiltIn)
        {
            self::query("DELETE FROM app_group_permissions WHERE `group_id` = :group_id", ['group_id' => $groupId]);
        }

        foreach ($catalog as $token => $meta)
        {
            $value = !empty($permissions[$token]) ? 1 : 0;

            self::query(
                "INSERT INTO app_group_permissions (`group_id`, `permission_token`, `permission_value`, `created_at`, `updated_at`)
                 VALUES (:group_id, :permission_token, :permission_value, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `permission_value` = IF(:preserve = 1, `permission_value`, VALUES(`permission_value`)),
                    `updated_at` = NOW()",
                [
                    'group_id' => $groupId,
                    'permission_token' => $token,
                    'permission_value' => $value,
                    'preserve' => $preserveBuiltIn ? 1 : 0,
                ]
            );
        }

        unset(self::$permissionMapCache[$groupId]);
    }

    /**
     * Flush cached group listing and permission data for the current request.
     *
     * @param int|null $groupId
     * @return void
     */
    private static function flushGroupCaches(?int $groupId = null): void
    {
        self::$groupListCache = [];

        if ($groupId !== null && $groupId > 0)
        {
            unset(self::$permissionMapCache[$groupId]);
            return;
        }

        self::$permissionMapCache = [];
    }

    /**
     * Return the stored default registration group id.
     *
     * @return int
     */
    public static function getDefaultRegistrationGroupId(): int
    {
        $row = SettingsModel::findSettingByKey('accounts.registration_default_group_id');
        return TypeHelper::toInt($row['value'] ?? 0) ?? 0;
    }

    /**
     * Return the default registration group row.
     *
     * @return array<string, mixed>|null
     */
    public static function getDefaultRegistrationGroup(): ?array
    {
        $groupId = self::getDefaultRegistrationGroupId();
        $group = self::findGroupById($groupId);

        if (!$group || empty($group['is_assignable']))
        {
            $group = self::findGroupBySlug('member');
        }

        return $group;
    }

    /**
     * Persist the default registration group id in app_settings_data.
     *
     * @param int $groupId
     * @return void
     */
    public static function setDefaultRegistrationGroupId(int $groupId): void
    {
        $categoryId = SettingsModel::getCategoryIdBySlug('accounts');
        if ($categoryId < 1)
        {
            SettingsModel::createCategory([
                'slug' => 'accounts',
                'title' => 'Accounts',
                'description' => 'Account workflow defaults used by the group and user management features.',
                'icon' => 'fa-users-gear',
                'sort_order' => 70,
                'is_system' => 1,
            ]);

            $categoryId = SettingsModel::getCategoryIdBySlug('accounts');
        }

        SettingsModel::upsertSetting([
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'key_name' => 'accounts.registration_default_group_id',
            'title' => 'Default Registration Group',
            'description' => 'Determines which assignable group newly registered accounts are created under before approval.',
            'value_data' => (string) $groupId,
            'type_name' => 'int',
            'input_type' => 'select',
            'sort_order' => 10,
            'is_system' => 1,
        ]);
    }

    /**
     * Return the next sort order value for new groups.
     *
     * @return int
     */
    public static function getNextSortOrder(): int
    {
        $row = self::fetch("SELECT COALESCE(MAX(`sort_order`), 0) AS sort_order FROM app_groups");
        return (TypeHelper::toInt($row['sort_order'] ?? 0) ?? 0) + 10;
    }
}
