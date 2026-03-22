<?php

/**
 * Settings registry and category data access helpers.
 */
class SettingsModel extends BaseModel
{
    /**
     * Fetch one stored setting row.
     *
     * @param string $key
     * @return array|null
     */
    public static function findSettingByKey(string $key): ?array
    {
        return self::fetch("SELECT * FROM app_settings_data WHERE `key` = :key LIMIT 1", ['key' => $key]);
    }

    /**
     * Fetch all stored settings category rows.
     *
     * @return array
     */
    public static function listCategories(): array
    {
        return self::fetchAll(
            "SELECT `id`, `slug`, `title`, `description`, `icon`, `sort_order`, `is_system`, `updated_at`
             FROM app_settings_categories
             ORDER BY `sort_order` ASC, `title` ASC"
        );
    }

    /**
     * Fetch all stored settings rows.
     *
     * @return array
     */
    public static function listSettingRows(): array
    {
        return self::fetchAll(
            "SELECT d.`id`, d.`category_id`, d.`key`, d.`title`, d.`description`, d.`value`, d.`type`, d.`input_type`, d.`sort_order`, d.`is_system`, d.`updated_at`,
                    c.`slug` AS category_slug
             FROM app_settings_data d
             LEFT JOIN app_settings_categories c ON c.`id` = d.`category_id`
             ORDER BY COALESCE(c.`sort_order`, 9999) ASC, d.`sort_order` ASC, d.`title` ASC"
        );
    }

    /**
     * Fetch one category row by slug.
     *
     * @param string $slug
     * @return array|null
     */
    public static function findCategoryBySlug(string $slug): ?array
    {
        return self::fetch(
            "SELECT `id`, `sort_order`, `is_system`
             FROM app_settings_categories
             WHERE `slug` = :slug
             LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * Fetch one category id by slug.
     *
     * @param string $slug
     * @return int
     */
    public static function getCategoryIdBySlug(string $slug): int
    {
        $row = self::fetch("SELECT `id` FROM app_settings_categories WHERE `slug` = :slug LIMIT 1", ['slug' => $slug]);
        return TypeHelper::toInt($row['id'] ?? 0) ?? 0;
    }

    /**
     * Fetch the next settings category sort order seed.
     *
     * @return int
     */
    public static function getNextCategorySortOrder(): int
    {
        $row = self::fetch("SELECT COALESCE(MAX(`sort_order`), 0) AS sort_order FROM app_settings_categories");
        return (TypeHelper::toInt($row['sort_order'] ?? 0) ?? 0) + 10;
    }

    /**
     * Upsert one settings category row.
     *
     * @param array $payload
     * @return void
     */
    public static function upsertCategory(array $payload): void
    {
        self::query(
            "INSERT INTO app_settings_categories (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
             VALUES (:slug, :title, :description, :icon, :sort_order, :is_system)
             ON DUPLICATE KEY UPDATE
                `title` = VALUES(`title`),
                `description` = VALUES(`description`),
                `icon` = VALUES(`icon`),
                `sort_order` = VALUES(`sort_order`),
                `is_system` = VALUES(`is_system`)",
            $payload
        );
    }

    /**
     * Insert one category row when no duplicate handling is needed.
     *
     * @param array $payload
     * @return void
     */
    public static function createCategory(array $payload): void
    {
        self::query(
            "INSERT INTO app_settings_categories (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
             VALUES (:slug, :title, :description, :icon, :sort_order, :is_system)",
            $payload
        );
    }

    /**
     * Upsert one stored settings data row.
     *
     * @param array $payload
     * @return void
     */
    public static function upsertSetting(array $payload): void
    {
        self::query(
            "INSERT INTO app_settings_data (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
             VALUES (:category_id, :key_name, :title, :description, :value_data, :type_name, :input_type, :sort_order, :is_system)
             ON DUPLICATE KEY UPDATE
                `category_id` = VALUES(`category_id`),
                `title` = VALUES(`title`),
                `description` = VALUES(`description`),
                `value` = VALUES(`value`),
                `type` = VALUES(`type`),
                `input_type` = VALUES(`input_type`),
                `sort_order` = VALUES(`sort_order`),
                `is_system` = VALUES(`is_system`)",
            $payload
        );
    }

    /**
     * Delete one stored category row.
     *
     * @param string $slug
     * @param bool $onlyCustom
     * @return void
     */
    public static function deleteCategoryBySlug(string $slug, bool $onlyCustom = false): void
    {
        if ($onlyCustom)
        {
            self::query("DELETE FROM app_settings_categories WHERE `slug` = :slug AND `is_system` = 0", ['slug' => $slug]);
            return;
        }

        self::query("DELETE FROM app_settings_categories WHERE `slug` = :slug", ['slug' => $slug]);
    }

    /**
     * Delete one stored setting row.
     *
     * @param string $key
     * @return void
     */
    public static function deleteSettingByKey(string $key): void
    {
        self::query("DELETE FROM app_settings_data WHERE `key` = :key", ['key' => $key]);
    }

    /**
     * Count settings entries that belong to one category slug.
     *
     * @param string $slug
     * @return int
     */
    public static function countEntriesForCategory(string $slug): int
    {
        $row = self::fetch(
            "SELECT COUNT(*) AS total
             FROM app_settings_data d
             LEFT JOIN app_settings_categories c ON c.`id` = d.`category_id`
             WHERE c.`slug` = :slug OR d.`key` LIKE :prefix",
            [
                'slug' => $slug,
                'prefix' => $slug . '.%',
            ]
        );

        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }
}
