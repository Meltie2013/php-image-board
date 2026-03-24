<?php

/**
 * SettingsRegistry
 *
 * Central registry for built-in settings metadata, fallback defaults, and
 * category presentation used by the installer, runtime settings loader, and
 * Control Panel settings UI.
 */
class SettingsRegistry
{
    /**
     * Return built-in settings categories.
     *
     * @return array
     */
    public static function getCategoryDefinitions(): array
    {
        return [
            'debugging' => [
                'title' => 'Debugging',
                'description' => 'Developer-only switches used while troubleshooting uploads, rendering, and runtime issues.',
                'icon' => 'fa-bug',
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'gallery' => [
                'title' => 'Gallery',
                'description' => 'Controls gallery page sizing, pagination, comments, and upload storage limits.',
                'icon' => 'fa-images',
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'profile' => [
                'title' => 'User Profile',
                'description' => 'Profile presentation defaults, avatar sizing, and age-gate requirements.',
                'icon' => 'fa-id-card',
                'sort_order' => 30,
                'is_system' => 1,
            ],
            'site' => [
                'title' => 'Site',
                'description' => 'Board identity, naming, and build metadata exposed across the public interface.',
                'icon' => 'fa-window-maximize',
                'sort_order' => 40,
                'is_system' => 1,
            ],
            'template' => [
                'title' => 'Template',
                'description' => 'Template engine behavior, cache handling, and approved helper functions.',
                'icon' => 'fa-code',
                'sort_order' => 50,
                'is_system' => 1,
            ],
            'upload' => [
                'title' => 'Upload',
                'description' => 'Controls how new upload hashes are generated and how upload behavior is presented.',
                'icon' => 'fa-upload',
                'sort_order' => 60,
                'is_system' => 1,
            ],
        ];
    }

    /**
     * Return built-in settings definitions.
     *
     * @return array
     */
    public static function getSettingDefinitions(): array
    {
        return [
            'debugging.allow_approve_uploads' => [
                'category' => 'debugging',
                'title' => 'Allow Upload Auto Approval',
                'description' => 'Lets uploads skip the normal review flow. Keep this disabled unless you are intentionally testing approval behavior on a trusted environment.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'This should normally remain disabled on a live board.',
                'default' => false,
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'debugging.allow_error_outputs' => [
                'category' => 'debugging',
                'title' => 'Display PHP Errors',
                'description' => 'Shows PHP errors directly in the browser while debugging application issues.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'Disable this on production so internal details are not exposed to visitors.',
                'default' => false,
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'gallery.comments_per_page' => [
                'category' => 'gallery',
                'title' => 'Comments Per Page',
                'description' => 'Controls how many comments appear at once on the gallery image view page before pagination is used.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 200,
                'help' => 'Higher values show more comments but can make long discussions heavier to load.',
                'default' => 5,
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'gallery.images_displayed' => [
                'category' => 'gallery',
                'title' => 'Images Per Gallery Page',
                'description' => 'Sets how many images are shown on each gallery page before the next page is created.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 200,
                'help' => 'Balance this against thumbnail size and page performance.',
                'default' => 24,
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'gallery.pagination_range' => [
                'category' => 'gallery',
                'title' => 'Pagination Range',
                'description' => 'Determines how many page links appear on each side of the current page in gallery pagination.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 20,
                'help' => 'Smaller values keep pagination compact, larger values expose more nearby pages.',
                'default' => 3,
                'sort_order' => 30,
                'is_system' => 1,
            ],
            'gallery.upload_max_image_size' => [
                'category' => 'gallery',
                'title' => 'Maximum Upload Size (MB)',
                'description' => 'Sets the largest image file size allowed for a single upload, measured in megabytes.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 10240,
                'help' => 'Make sure your PHP upload limits also support this value.',
                'default' => 3,
                'sort_order' => 40,
                'is_system' => 1,
            ],
            'gallery.upload_max_storage' => [
                'category' => 'gallery',
                'title' => 'Maximum Gallery Storage',
                'description' => 'Defines the total storage available for uploaded images using shorthand values such as 500mb, 10gb, or 1tb.',
                'type' => 'string',
                'input' => 'text',
                'placeholder' => '10gb',
                'help' => 'StorageHelper converts this shorthand into bytes internally.',
                'default' => '10gb',
                'sort_order' => 50,
                'is_system' => 1,
            ],
            'gallery.max_width' => [
                'category' => 'gallery',
                'title' => 'Maximum Upload Width',
                'description' => 'Largest image width accepted during upload validation, measured in pixels.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 50000,
                'help' => 'Uploads wider than this are rejected before processing continues.',
                'default' => 8000,
                'sort_order' => 60,
                'is_system' => 1,
            ],
            'gallery.max_height' => [
                'category' => 'gallery',
                'title' => 'Maximum Upload Height',
                'description' => 'Largest image height accepted during upload validation, measured in pixels.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 50000,
                'help' => 'Uploads taller than this are rejected before processing continues.',
                'default' => 8000,
                'sort_order' => 70,
                'is_system' => 1,
            ],
            'gallery.max_pixels' => [
                'category' => 'gallery',
                'title' => 'Maximum Upload Pixel Count',
                'description' => 'Maximum total pixel count allowed for one uploaded image.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 500000000,
                'help' => 'Use this alongside width and height limits to block unusually large images.',
                'default' => 40000000,
                'sort_order' => 80,
                'is_system' => 1,
            ],
            'profile.avatar_size' => [
                'category' => 'profile',
                'title' => 'Default Avatar Size',
                'description' => 'Sets the default avatar display size in pixels for user profile areas.',
                'type' => 'int',
                'input' => 'number',
                'min' => 16,
                'max' => 1024,
                'help' => 'This affects visual layout on profile-related pages.',
                'default' => 250,
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'profile.years' => [
                'category' => 'profile',
                'title' => 'Sensitive Content Age Requirement',
                'description' => 'Defines the minimum age required for users to view content protected by the board age gate.',
                'type' => 'int',
                'input' => 'number',
                'min' => 0,
                'max' => 99,
                'help' => 'Use the legal or community requirement that matches your board rules.',
                'default' => 13,
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'profile.age_gate_enabled' => [
                'category' => 'profile',
                'title' => 'Enable Age Gate',
                'description' => 'Enable gated access rules for sensitive and explicit image content.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'When disabled, the board will not lock sensitive or explicit images behind the account content-access workflow.',
                'default' => true,
                'sort_order' => 21,
                'is_system' => 1,
            ],
            'profile.self_serve_age_gate' => [
                'category' => 'profile',
                'title' => 'Allow Self-Serve Mature Access',
                'description' => 'Allow registered users to unlock sensitive content access without submitting a date of birth.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'Explicit content still requires date of birth verification even when this option is enabled.',
                'default' => true,
                'sort_order' => 22,
                'is_system' => 1,
            ],
            'profile.explicit_years' => [
                'category' => 'profile',
                'title' => 'Explicit Content Age Requirement',
                'description' => 'Defines the minimum age required for explicitly tagged content.',
                'type' => 'int',
                'input' => 'number',
                'min' => 0,
                'max' => 99,
                'help' => 'Use this stricter threshold for the explicit content tier.',
                'default' => 18,
                'sort_order' => 23,
                'is_system' => 1,
            ],
            'profile.birthday_badge_enabled' => [
                'category' => 'profile',
                'title' => 'Enable Birthday Badge',
                'description' => 'Show a birthday cake icon beside usernames on the member’s birthday.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'This uses the stored date of birth month and day only for the visible birthday badge.',
                'default' => true,
                'sort_order' => 24,
                'is_system' => 1,
            ],
            'profile.avatar_max_pixels' => [
                'category' => 'profile',
                'title' => 'Maximum Avatar Pixel Count',
                'description' => 'Maximum total pixel count allowed for uploaded avatar images.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 100000000,
                'help' => 'This helps block avatars with extremely large dimensions even when the file size is small.',
                'default' => 16000000,
                'sort_order' => 30,
                'is_system' => 1,
            ],
            'profile.avatar_max_upload_size_mb' => [
                'category' => 'profile',
                'title' => 'Maximum Avatar Upload Size (MB)',
                'description' => 'Largest avatar file size accepted during avatar uploads, measured in megabytes.',
                'type' => 'int',
                'input' => 'number',
                'min' => 1,
                'max' => 1024,
                'help' => 'Make sure your PHP upload limits are not lower than this value.',
                'default' => 5,
                'sort_order' => 40,
                'is_system' => 1,
            ],
            'site.name' => [
                'category' => 'site',
                'title' => 'Site Name',
                'description' => 'Primary board name shown in the header, footer, page titles, and shared templates.',
                'type' => 'string',
                'input' => 'text',
                'placeholder' => 'PHP Image Board',
                'help' => 'Keep this short enough to fit cleanly across the interface.',
                'default' => 'PHP Image Board',
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'site.version' => [
                'category' => 'site',
                'title' => 'Build Version',
                'description' => 'Version or build string shown in the interface for release tracking and support reference.',
                'type' => 'string',
                'input' => 'text',
                'placeholder' => '0.2.3',
                'help' => 'You can use a release number, semantic version, or short build identifier.',
                'default' => '0.2.3',
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'template.allowed_functions' => [
                'category' => 'template',
                'title' => 'Allowed Template Functions',
                'description' => 'JSON array of trusted PHP function names that templates may call. This list should stay minimal and only contain simple, safe helpers.',
                'type' => 'json',
                'input' => 'json',
                'help' => 'Enter a JSON array such as ["strtoupper","ucfirst"]. Only plain function names are accepted and duplicates are removed automatically.',
                'default' => ['strtoupper', 'strtolower', 'ucfirst', 'lcfirst'],
                'sort_order' => 10,
                'is_system' => 1,
            ],
            'template.disable_cache' => [
                'category' => 'template',
                'title' => 'Disable Template Cache',
                'description' => 'Turns off compiled template caching so visual and template updates appear immediately during development.',
                'type' => 'bool',
                'input' => 'bool',
                'help' => 'Leave caching enabled on production unless you are actively debugging template changes.',
                'default' => true,
                'sort_order' => 20,
                'is_system' => 1,
            ],
            'upload.hash_type' => [
                'category' => 'upload',
                'title' => 'Generated Image Hash Format',
                'description' => 'Chooses the character format used when generating new image hashes for uploads.',
                'type' => 'string',
                'input' => 'select',
                'help' => 'Changing this only affects newly uploaded images. Existing image hashes stay the same.',
                'default' => 'mixed_lower',
                'options' => [
                    ['all_digits', 'All Digits'],
                    ['all_letters_lower', 'All Lowercase Letters'],
                    ['all_letters_upper', 'All Uppercase Letters'],
                    ['mixed_lower', 'Mixed Lowercase'],
                    ['mixed_upper', 'Mixed Uppercase'],
                ],
                'sort_order' => 10,
                'is_system' => 1,
            ],
        ];
    }

    /**
     * Return built-in icon options for category editing.
     *
     * @return array
     */
    public static function getCategoryIconOptions(): array
    {
        return [
            ['fa-sliders', 'Sliders'],
            ['fa-bug', 'Bug'],
            ['fa-images', 'Images'],
            ['fa-id-card', 'ID Card'],
            ['fa-window-maximize', 'Window'],
            ['fa-code', 'Code'],
            ['fa-upload', 'Upload'],
            ['fa-plug', 'Plug'],
            ['fa-shield-halved', 'Shield'],
            ['fa-server', 'Server'],
            ['fa-gears', 'Gears'],
            ['fa-wrench', 'Wrench'],
            ['fa-users-gear', 'Users Gear'],
            ['fa-hard-drive', 'Hard Drive'],
            ['fa-network-wired', 'Network'],
        ];
    }

    /**
     * Build fallback configuration values from built-in setting defaults.
     *
     * @return array
     */
    public static function getFallbackConfig(): array
    {
        $config = [];

        foreach (self::getSettingDefinitions() as $key => $definition)
        {
            $config = self::setInArray($config, $key, $definition['default'] ?? null);
        }

        return $config;
    }

    /**
     * Build seed category rows for installer or migrations.
     *
     * @return array
     */
    public static function buildCategorySeedRows(): array
    {
        $rows = [];
        foreach (self::getCategoryDefinitions() as $slug => $meta)
        {
            $rows[] = [
                'slug' => $slug,
                'title' => (string) ($meta['title'] ?? self::humanizeToken($slug)),
                'description' => (string) ($meta['description'] ?? ''),
                'icon' => (string) ($meta['icon'] ?? 'fa-sliders'),
                'sort_order' => (int) ($meta['sort_order'] ?? 0),
                'is_system' => !empty($meta['is_system']) ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * Build seed setting rows for installer or migrations.
     *
     * @return array
     */
    public static function buildSettingSeedRows(): array
    {
        $rows = [];
        foreach (self::getSettingDefinitions() as $key => $definition)
        {
            $type = self::normalizeType((string) ($definition['type'] ?? 'string'));
            $rows[] = [
                'key' => $key,
                'category_slug' => self::normalizeCategorySlug((string) ($definition['category'] ?? self::inferCategoryFromKey($key))),
                'title' => (string) ($definition['title'] ?? self::humanizeToken($key)),
                'description' => (string) ($definition['description'] ?? ''),
                'value' => self::normalizeValueForStorage($key, $definition['default'] ?? '', $type)['value'],
                'type' => $type,
                'input_type' => (string) ($definition['input'] ?? self::defaultInputForType($type)),
                'sort_order' => (int) ($definition['sort_order'] ?? 0),
                'is_system' => !empty($definition['is_system']) ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * Create a generated definition for a custom database-backed setting.
     *
     * @param string $key
     * @param string $type
     * @param array|null $row
     * @return array
     */
    public static function buildGeneratedDefinition(string $key, string $type = 'string', ?array $row = null): array
    {
        $category = self::inferCategoryFromKey($key);
        $title = trim((string) ($row['title'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));

        if ($title === '')
        {
            $parts = explode('.', $key, 2);
            $title = self::humanizeToken($parts[1] ?? $key);
        }

        if ($description === '')
        {
            $description = 'Custom database-backed setting for this configuration category.';
        }

        return [
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'type' => self::normalizeType($type),
            'input' => self::defaultInputForType($type),
            'help' => 'This setting is not part of the built-in catalog, so generic editing rules are being used.',
            'default' => '',
            'sort_order' => 9999,
            'is_system' => 0,
        ];
    }

    /**
     * Infer the category slug from a dot-notation key.
     *
     * @param string $key
     * @return string
     */
    public static function inferCategoryFromKey(string $key): string
    {
        $parts = explode('.', self::normalizeKey($key), 2);
        $slug = self::normalizeCategorySlug((string) ($parts[0] ?? ''));
        return $slug !== '' ? $slug : 'custom';
    }

    /**
     * Normalize a registry key.
     *
     * @param string $key
     * @return string
     */
    public static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '')
        {
            return '';
        }

        $key = str_replace(['/', '\\', ':'], '.', $key);
        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '', $key);
        $key = preg_replace('/\.+/', '.', $key);
        $key = trim($key, '.');

        if (strlen($key) > 128)
        {
            $key = substr($key, 0, 128);
        }

        return $key;
    }

    /**
     * Normalize a category slug.
     *
     * @param string $category
     * @return string
     */
    public static function normalizeCategorySlug(string $category): string
    {
        $category = strtolower(trim($category));
        $category = preg_replace('/[^a-z0-9_.-]/', '', $category);
        $category = trim($category, '.-_');

        if (strlen($category) > 64)
        {
            $category = substr($category, 0, 64);
        }

        return $category;
    }

    /**
     * Normalize a setting type.
     *
     * @param string $type
     * @return string
     */
    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['string', 'int', 'bool', 'json'], true) ? $type : 'string';
    }

    /**
     * Convert a token to title case.
     *
     * @param string $value
     * @return string
     */
    public static function humanizeToken(string $value): string
    {
        $value = str_replace(['.', '_', '-'], ' ', trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = strtolower($value);

        return ucwords($value);
    }

    /**
     * Normalize one setting value for safe DB storage.
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @return array
     */
    public static function normalizeValueForStorage(string $key, $value, string $type): array
    {
        $type = self::normalizeType($type);
        $normalizedValue = '';
        $error = '';

        switch ($type)
        {
            case 'bool':
                $normalizedValue = self::normalizeBoolStorageValue($value);
                break;

            case 'int':
                $value = trim((string) $value);
                if ($value === '' || !preg_match('/^-?\d+$/', $value))
                {
                    return ['valid' => false, 'value' => '', 'error' => 'value'];
                }
                $normalizedValue = (string) ((int) $value);
                break;

            case 'json':
                if (!is_string($value))
                {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $value = trim((string) $value);
                if ($value === '')
                {
                    $value = '[]';
                }

                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE)
                {
                    return ['valid' => false, 'value' => '', 'error' => 'value'];
                }

                if ($key === 'template.allowed_functions')
                {
                    if (!is_array($decoded))
                    {
                        return ['valid' => false, 'value' => '', 'error' => 'value'];
                    }

                    $allowedFunctions = [];
                    foreach ($decoded as $functionName)
                    {
                        if (!is_string($functionName))
                        {
                            continue;
                        }

                        $functionName = trim($functionName);
                        if ($functionName === '')
                        {
                            continue;
                        }

                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $functionName))
                        {
                            continue;
                        }

                        $allowedFunctions[] = $functionName;
                    }

                    $allowedFunctions = array_values(array_unique($allowedFunctions));
                    $normalizedValue = json_encode($allowedFunctions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                else
                {
                    $normalizedValue = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                if (!is_string($normalizedValue))
                {
                    return ['valid' => false, 'value' => '', 'error' => 'value'];
                }
                break;

            case 'string':
            default:
                $normalizedValue = trim((string) $value);

                if ($key === 'upload.hash_type')
                {
                    $allowedHashTypes = [
                        'all_digits',
                        'all_letters_lower',
                        'all_letters_upper',
                        'mixed_lower',
                        'mixed_upper',
                    ];

                    if (!in_array($normalizedValue, $allowedHashTypes, true))
                    {
                        return ['valid' => false, 'value' => '', 'error' => 'value'];
                    }
                }
                break;
        }

        return [
            'valid' => $error === '',
            'value' => $normalizedValue,
            'error' => $error,
        ];
    }

    /**
     * Default form control for one type.
     *
     * @param string $type
     * @return string
     */
    public static function defaultInputForType(string $type): string
    {
        return match (self::normalizeType($type)) {
            'bool' => 'bool',
            'int' => 'number',
            'json' => 'json',
            default => 'text',
        };
    }

    /**
     * Normalize a bool-like value to database storage.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeBoolStorageValue($value): string
    {
        if (is_bool($value))
        {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value))
        {
            return ((int) $value) === 1 ? '1' : '0';
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true) ? '1' : '0';
    }

    /**
     * Set a nested value in a config array using dot notation.
     *
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    private static function setInArray(array $array, string $key, $value): array
    {
        $parts = explode('.', self::normalizeKey($key));
        if (empty($parts))
        {
            return $array;
        }

        $ref = &$array;
        foreach ($parts as $part)
        {
            if ($part === '')
            {
                continue;
            }

            if (!isset($ref[$part]) || !is_array($ref[$part]))
            {
                $ref[$part] = [];
            }

            $ref = &$ref[$part];
        }

        $ref = $value;
        return $array;
    }
}
