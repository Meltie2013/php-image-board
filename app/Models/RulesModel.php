<?php

/**
 * RulesModel
 *
 * Stores rules categories, rule entries, release versions, and per-user
 * acceptance state for the site rules workflow.
 */
class RulesModel extends BaseModel
{
    /**
     * Cached schema availability state for the current request.
     *
     * @var bool|null
     */
    private static ?bool $schemaAvailable = null;

    /**
     * Per-request built-in seed guard.
     *
     * @var bool
     */
    private static bool $builtInDataChecked = false;

    /**
     * Rules seed cache file path relative to storage/cache.
     */
    private const BUILT_IN_SEED_CACHE_FILE = 'rules_seed_state.php';

    /**
     * Increment this when the starter rules seed changes.
     */
    private const BUILT_IN_SEED_VERSION = '2026-03-24-rules-v1';

    /**
     * Determine whether the rules schema is available.
     *
     * @return bool
     */
    public static function isSchemaAvailable(): bool
    {
        if (self::$schemaAvailable !== null)
        {
            return self::$schemaAvailable;
        }

        try
        {
            self::fetch("SELECT id FROM app_rule_categories LIMIT 1");
            self::fetch("SELECT id FROM app_rules LIMIT 1");
            self::fetch("SELECT id FROM app_rule_releases LIMIT 1");
            self::fetch("SELECT id FROM app_rule_acceptances LIMIT 1");
            self::$schemaAvailable = true;
        }
        catch (Throwable $e)
        {
            self::$schemaAvailable = false;
        }

        return self::$schemaAvailable;
    }

    /**
     * Seed starter categories, starter rules, and the initial release when the
     * rules schema is available but still empty.
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

        if (!self::isSchemaAvailable())
        {
            return;
        }

        if (self::isBuiltInSeedCacheFresh())
        {
            return;
        }

        $seedWritten = false;

        try
        {
            Database::beginTransaction();

            $categoryCountRow = self::fetch("SELECT COUNT(*) AS total FROM app_rule_categories");
            $categoryCount = TypeHelper::toInt($categoryCountRow['total'] ?? 0) ?? 0;

            if ($categoryCount < 1)
            {
                $accountCategoryId = self::saveCategory([
                    'title' => 'Account Rules',
                    'slug' => 'account-rules',
                    'description' => 'Account ownership, security, and access expectations for registered members.',
                    'sort_order' => 10,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                self::saveRule([
                    'category_id' => $accountCategoryId,
                    'title' => 'One Account Per Person',
                    'slug' => 'one-account-per-person',
                    'body' => "Each member is expected to maintain one primary account unless staff has approved an exception.\n\nAccounts created to evade moderation, bans, or restrictions may be suspended or removed.",
                    'sort_order' => 10,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);
                self::saveRule([
                    'category_id' => $accountCategoryId,
                    'title' => 'Protect Your Login',
                    'slug' => 'protect-your-login',
                    'body' => "Keep your password private and use an email address you still control.\n\nSharing accounts or allowing others to operate your account is done at your own risk and may affect moderation decisions.",
                    'sort_order' => 20,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                $contentCategoryId = self::saveCategory([
                    'title' => 'Content Rules',
                    'slug' => 'content-rules',
                    'description' => 'Submission standards for uploads, edits, reports, and content ratings.',
                    'sort_order' => 20,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                self::saveRule([
                    'category_id' => $contentCategoryId,
                    'title' => 'Upload Only Content You Can Share',
                    'slug' => 'upload-only-content-you-can-share',
                    'body' => "Do not upload content that you do not own, do not have permission to share, or that violates platform policy.\n\nStaff may remove content that is miscategorized, illegal, or otherwise unsafe for the site.",
                    'sort_order' => 10,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);
                self::saveRule([
                    'category_id' => $contentCategoryId,
                    'title' => 'Use Accurate Ratings and Reports',
                    'slug' => 'use-accurate-ratings-and-reports',
                    'body' => "Apply the correct content rating whenever content is edited or uploaded.\n\nFalse reports, malicious edits, or attempts to hide sensitive content may lead to moderation action.",
                    'sort_order' => 20,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                $communityCategoryId = self::saveCategory([
                    'title' => 'Community Conduct',
                    'slug' => 'community-conduct',
                    'description' => 'General behavior expectations for comments, moderation reports, and user interaction.',
                    'sort_order' => 30,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);

                self::saveRule([
                    'category_id' => $communityCategoryId,
                    'title' => 'Keep Interactions Respectful',
                    'slug' => 'keep-interactions-respectful',
                    'body' => "Harassment, threats, and repeated abusive conduct are not allowed.\n\nUse the reporting tools responsibly and let staff handle escalations when needed.",
                    'sort_order' => 10,
                    'is_active' => 1,
                    'created_by' => null,
                    'updated_by' => null,
                ]);
            }

            $releaseCountRow = self::fetch("SELECT COUNT(*) AS total FROM app_rule_releases");
            $releaseCount = TypeHelper::toInt($releaseCountRow['total'] ?? 0) ?? 0;
            if ($releaseCount < 1)
            {
                self::publishCurrentRulesRelease(null, 'Initial rules publication.', max(0, TypeHelper::toInt(SettingsManager::get('rules.enforcement_window_days', 14)) ?? 14));
            }

            Database::commit();
            $seedWritten = true;
        }
        catch (Throwable $e)
        {
            if (Database::getPDO()->inTransaction())
            {
                Database::rollBack();
            }
        }

        if ($seedWritten)
        {
            self::writeBuiltInSeedCache();
        }
    }

    /**
     * Determine whether the rules seed cache is fresh.
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
     * Write the rules seed cache marker file.
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

        @file_put_contents($path, "<?php\nreturn " . $payload . ";\n");
    }

    /**
     * Return the absolute rules seed cache path.
     *
     * @return string
     */
    private static function getBuiltInSeedCachePath(): string
    {
        return rtrim(CACHE_PATH, '/') . '/' . self::BUILT_IN_SEED_CACHE_FILE;
    }

    /**
     * Return active categories with active rules for the public rules page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listPublicCategoriesWithRules(): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        $categories = self::fetchAll(
            "SELECT id, title, slug, description, sort_order
             FROM app_rule_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );

        foreach ($categories as &$category)
        {
            $categoryId = TypeHelper::toInt($category['id'] ?? 0) ?? 0;
            $category['rules'] = self::fetchAll(
                "SELECT id, title, slug, body, sort_order
                 FROM app_rules
                 WHERE category_id = :category_id
                   AND is_active = 1
                 ORDER BY sort_order ASC, id ASC",
                ['category_id' => $categoryId]
            );
        }
        unset($category);

        return $categories;
    }

    /**
     * Return category summaries for the rules control panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listCategorySummaries(): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        return self::fetchAll(
            "SELECT c.id,
                    c.title,
                    c.slug,
                    c.description,
                    c.sort_order,
                    c.is_active,
                    COUNT(r.id) AS rule_count
             FROM app_rule_categories c
             LEFT JOIN app_rules r ON r.category_id = c.id
             GROUP BY c.id, c.title, c.slug, c.description, c.sort_order, c.is_active
             ORDER BY c.sort_order ASC, c.id ASC"
        );
    }

    /**
     * Return the rules control-panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listRulesForAdmin(): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        return self::fetchAll(
            "SELECT r.id,
                    r.category_id,
                    c.title AS category_title,
                    r.title,
                    r.slug,
                    r.body,
                    r.sort_order,
                    r.is_active,
                    r.updated_at
             FROM app_rules r
             INNER JOIN app_rule_categories c ON r.category_id = c.id
             ORDER BY c.sort_order ASC, c.id ASC, r.sort_order ASC, r.id ASC"
        );
    }

    /**
     * Return category options for rules editor forms.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listCategoryOptions(): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        return self::fetchAll(
            "SELECT id, title
             FROM app_rule_categories
             ORDER BY sort_order ASC, id ASC"
        );
    }

    /**
     * Find one category row.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findCategoryById(int $id): ?array
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id, title, slug, description, sort_order, is_active
             FROM app_rule_categories
             WHERE id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Find one category by slug.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public static function findCategoryBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id, title, slug, description, sort_order, is_active
             FROM app_rule_categories
             WHERE slug = :slug
             LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * Create or update one category row.
     *
     * @param array<string, mixed> $data
     * @return int
     */
    public static function saveCategory(array $data): int
    {
        if (!self::isSchemaAvailable())
        {
            return 0;
        }

        $id = TypeHelper::toInt($data['id'] ?? 0) ?? 0;
        $title = trim(TypeHelper::toString($data['title'] ?? '', allowEmpty: true) ?? '');
        $slug = trim(TypeHelper::toString($data['slug'] ?? '', allowEmpty: true) ?? '');
        $description = trim(TypeHelper::toString($data['description'] ?? '', allowEmpty: true) ?? '');
        $sortOrder = TypeHelper::toInt($data['sort_order'] ?? 0) ?? 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $createdBy = TypeHelper::toInt($data['created_by'] ?? null);
        $updatedBy = TypeHelper::toInt($data['updated_by'] ?? null);

        if ($id > 0)
        {
            self::execute(
                "UPDATE app_rule_categories
                 SET title = :title,
                     slug = :slug,
                     description = :description,
                     sort_order = :sort_order,
                     is_active = :is_active,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'id' => $id,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'updated_by' => $updatedBy,
                ]
            );

            return $id;
        }

        return (int) self::insert(
            "INSERT INTO app_rule_categories (
                title,
                slug,
                description,
                sort_order,
                is_active,
                created_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :title,
                :slug,
                :description,
                :sort_order,
                :is_active,
                :created_by,
                :updated_by,
                NOW(),
                NOW()
             )",
            [
                'title' => $title,
                'slug' => $slug,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'created_by' => $createdBy,
                'updated_by' => $updatedBy,
            ]
        );
    }

    /**
     * Find one rule row.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findRuleById(int $id): ?array
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id, category_id, title, slug, body, sort_order, is_active
             FROM app_rules
             WHERE id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Find one rule by slug.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public static function findRuleBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id, category_id, title, slug, body, sort_order, is_active
             FROM app_rules
             WHERE slug = :slug
             LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * Create or update one rule row.
     *
     * @param array<string, mixed> $data
     * @return int
     */
    public static function saveRule(array $data): int
    {
        if (!self::isSchemaAvailable())
        {
            return 0;
        }

        $id = TypeHelper::toInt($data['id'] ?? 0) ?? 0;
        $categoryId = TypeHelper::toInt($data['category_id'] ?? 0) ?? 0;
        $title = trim(TypeHelper::toString($data['title'] ?? '', allowEmpty: true) ?? '');
        $slug = trim(TypeHelper::toString($data['slug'] ?? '', allowEmpty: true) ?? '');
        $body = trim(TypeHelper::toString($data['body'] ?? '', allowEmpty: true) ?? '');
        $sortOrder = TypeHelper::toInt($data['sort_order'] ?? 0) ?? 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $createdBy = TypeHelper::toInt($data['created_by'] ?? null);
        $updatedBy = TypeHelper::toInt($data['updated_by'] ?? null);

        if ($id > 0)
        {
            self::execute(
                "UPDATE app_rules
                 SET category_id = :category_id,
                     title = :title,
                     slug = :slug,
                     body = :body,
                     sort_order = :sort_order,
                     is_active = :is_active,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'id' => $id,
                    'category_id' => $categoryId,
                    'title' => $title,
                    'slug' => $slug,
                    'body' => $body,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'updated_by' => $updatedBy,
                ]
            );

            return $id;
        }

        return (int) self::insert(
            "INSERT INTO app_rules (
                category_id,
                title,
                slug,
                body,
                sort_order,
                is_active,
                created_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :category_id,
                :title,
                :slug,
                :body,
                :sort_order,
                :is_active,
                :created_by,
                :updated_by,
                NOW(),
                NOW()
             )",
            [
                'category_id' => $categoryId,
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'created_by' => $createdBy,
                'updated_by' => $updatedBy,
            ]
        );
    }

    /**
     * Return the current rules release.
     *
     * @return array<string, mixed>|null
     */
    public static function findCurrentRelease(): ?array
    {
        if (!self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id,
                    version_label,
                    summary,
                    grace_days,
                    published_by,
                    published_at,
                    created_at,
                    updated_at
             FROM app_rule_releases
             ORDER BY published_at DESC, id DESC
             LIMIT 1"
        );
    }

    /**
     * Find the current release acceptance row for one user.
     *
     * @param int $userId
     * @return array<string, mixed>|null
     */
    public static function findCurrentAcceptanceForUser(int $userId): ?array
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        $release = self::findCurrentRelease();
        if (!$release)
        {
            return null;
        }

        return self::findAcceptanceForReleaseAndUser(TypeHelper::toInt($release['id'] ?? 0) ?? 0, $userId);
    }

    /**
     * Find one acceptance row by release and user.
     *
     * @param int $releaseId
     * @param int $userId
     * @return array<string, mixed>|null
     */
    public static function findAcceptanceForReleaseAndUser(int $releaseId, int $userId): ?array
    {
        if ($releaseId < 1 || $userId < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id,
                    release_id,
                    user_id,
                    status,
                    enforce_after,
                    accepted_at,
                    notified_at,
                    created_at,
                    updated_at
             FROM app_rule_acceptances
             WHERE release_id = :release_id
               AND user_id = :user_id
             LIMIT 1",
            [
                'release_id' => $releaseId,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * Ensure one acceptance row exists for the current release and user.
     *
     * This covers new users created after the current rules release as well as
     * older accounts that might not yet have a pending row due to an import or
     * previously missing migration step.
     *
     * @param int $userId
     * @return array<string, mixed>|null
     */
    public static function ensureCurrentReleaseAcceptanceForUser(int $userId): ?array
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        $release = self::findCurrentRelease();
        if (!$release)
        {
            return null;
        }

        $releaseId = TypeHelper::toInt($release['id'] ?? 0) ?? 0;
        if ($releaseId < 1)
        {
            return null;
        }

        $existing = self::findAcceptanceForReleaseAndUser($releaseId, $userId);
        if ($existing)
        {
            return $existing;
        }

        $user = self::fetch(
            "SELECT u.id, u.created_at, u.status, g.slug AS group_slug
             FROM app_users u
             LEFT JOIN app_groups g ON u.group_id = g.id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $userId]
        );
        if (!$user)
        {
            return null;
        }

        $status = strtolower(TypeHelper::toString($user['status'] ?? '', allowEmpty: true) ?? '');
        $groupSlug = strtolower(TypeHelper::toString($user['group_slug'] ?? '', allowEmpty: true) ?? '');
        if ($status !== 'active' || $groupSlug === 'banned')
        {
            return null;
        }

        $userCreatedAt = strtotime(TypeHelper::toString($user['created_at'] ?? '', allowEmpty: true) ?? '');
        $publishedAt = strtotime(TypeHelper::toString($release['published_at'] ?? '', allowEmpty: true) ?? '');
        $graceDays = max(0, TypeHelper::toInt($release['grace_days'] ?? 14) ?? 14);

        $enforceAfter = gmdate('Y-m-d H:i:s');
        if ($publishedAt > 0 && $userCreatedAt > 0 && $userCreatedAt < $publishedAt)
        {
            $enforceAfter = gmdate('Y-m-d H:i:s', strtotime('+' . $graceDays . ' days', $publishedAt));
        }

        $shouldNotify = $publishedAt > 0 && $userCreatedAt > 0 && $userCreatedAt < $publishedAt;

        self::insert(
            "INSERT INTO app_rule_acceptances (
                release_id,
                user_id,
                status,
                enforce_after,
                accepted_at,
                notified_at,
                created_at,
                updated_at
             ) VALUES (
                :release_id,
                :user_id,
                'pending',
                :enforce_after,
                NULL,
                :notified_at,
                NOW(),
                NOW()
             )",
            [
                'release_id' => $releaseId,
                'user_id' => $userId,
                'enforce_after' => $enforceAfter,
                'notified_at' => $shouldNotify ? gmdate('Y-m-d H:i:s') : null,
            ]
        );

        if ($shouldNotify)
        {
            $message = 'The site rules have been updated. Please review and accept the latest rules.';
            if (strtotime($enforceAfter) > time())
            {
                $message = 'The site rules have been updated. Please review and accept them before ' . DateHelper::date_only_format($enforceAfter, 'F j, Y \a\t g:i a') . '.';
            }
            else if ($graceDays < 1)
            {
                $message = 'The site rules have been updated. Please review and accept them now.';
            }

            NotificationModel::create(
                $userId,
                'rules_update',
                'Rules Updated',
                $message,
                '/community/rules'
            );
        }

        return self::findAcceptanceForReleaseAndUser($releaseId, $userId);
    }

    /**
     * Mark the current rules release as accepted for one user.
     *
     * @param int $userId
     * @return bool
     */
    public static function acceptCurrentReleaseForUser(int $userId): bool
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return false;
        }

        $acceptance = self::ensureCurrentReleaseAcceptanceForUser($userId);
        if (!$acceptance)
        {
            return false;
        }

        $acceptanceId = TypeHelper::toInt($acceptance['id'] ?? 0) ?? 0;
        if ($acceptanceId < 1)
        {
            return false;
        }

        self::execute(
            "UPDATE app_rule_acceptances
             SET status = 'accepted',
                 accepted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            ['id' => $acceptanceId]
        );

        NotificationModel::markRulesNotificationsReadForUser($userId);
        return true;
    }

    /**
     * Publish the current rules set as a new release and notify active users.
     *
     * @param int|null $publishedBy
     * @param string $summary
     * @param int $graceDays
     * @return int
     */
    public static function publishCurrentRulesRelease(?int $publishedBy = null, string $summary = '', int $graceDays = 14): int
    {
        if (!self::isSchemaAvailable())
        {
            return 0;
        }

        $graceDays = max(0, $graceDays);
        $publishedAt = gmdate('Y-m-d H:i:s');
        $enforceAfter = gmdate('Y-m-d H:i:s', strtotime('+' . $graceDays . ' days', strtotime($publishedAt)));
        $versionLabel = 'Rules Update - ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $summary = trim($summary);
        if ($summary === '')
        {
            $summary = 'Rules updated from the Control Panel.';
        }

        $releaseId = (int) self::insert(
            "INSERT INTO app_rule_releases (
                version_label,
                summary,
                grace_days,
                published_by,
                published_at,
                created_at,
                updated_at
             ) VALUES (
                :version_label,
                :summary,
                :grace_days,
                :published_by,
                :published_at,
                NOW(),
                NOW()
             )",
            [
                'version_label' => $versionLabel,
                'summary' => $summary,
                'grace_days' => $graceDays,
                'published_by' => $publishedBy,
                'published_at' => $publishedAt,
            ]
        );

        if ($releaseId < 1)
        {
            return 0;
        }

        self::execute(
            "INSERT INTO app_rule_acceptances (
                release_id,
                user_id,
                status,
                enforce_after,
                accepted_at,
                notified_at,
                created_at,
                updated_at
             )
             SELECT :release_id,
                    u.id,
                    'pending',
                    :enforce_after,
                    NULL,
                    NOW(),
                    NOW(),
                    NOW()
             FROM app_users u
             LEFT JOIN app_groups g ON u.group_id = g.id
             WHERE u.status = 'active'
               AND COALESCE(g.slug, '') <> 'banned'",
            [
                'release_id' => $releaseId,
                'enforce_after' => $enforceAfter,
            ]
        );

        if (NotificationModel::isSchemaAvailable())
        {
            self::execute(
                "INSERT INTO app_notifications (
                    user_id,
                    notification_type,
                    title,
                    message,
                    link_url,
                    is_read,
                    read_at,
                    created_at,
                    updated_at
                 )
                 SELECT u.id,
                        'rules_update',
                        :title,
                        :message,
                        '/community/rules',
                        0,
                        NULL,
                        NOW(),
                        NOW()
                 FROM app_users u
                 LEFT JOIN app_groups g ON u.group_id = g.id
                 WHERE u.status = 'active'
                   AND COALESCE(g.slug, '') <> 'banned'",
                [
                    'title' => 'Rules Updated',
                    'message' => $graceDays > 0
                        ? 'The site rules have been updated. Please review and accept them before ' . DateHelper::date_only_format($enforceAfter, 'F j, Y \a\t g:i a') . '.'
                        : 'The site rules have been updated. Please review and accept them now.',
                ]
            );
        }

        return $releaseId;
    }
}
