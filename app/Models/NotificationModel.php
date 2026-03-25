<?php

/**
 * NotificationModel
 *
 * Lightweight inbox and unread-count helpers used by the shared header
 * and all user-facing notification flows.
 */
class NotificationModel extends BaseModel
{
    /**
     * Cached schema availability state for the current request.
     *
     * @var bool|null
     */
    private static ?bool $schemaAvailable = null;

    /**
     * Determine whether the notifications table is available.
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
            self::fetch("SELECT id FROM app_notifications LIMIT 1");
            self::$schemaAvailable = true;
        }
        catch (Throwable $e)
        {
            self::$schemaAvailable = false;
        }

        return self::$schemaAvailable;
    }

    /**
     * Count unread notifications for one user.
     *
     * @param int $userId
     * @return int
     */
    public static function countUnreadForUser(int $userId): int
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return 0;
        }

        $row = self::fetch(
            "SELECT COUNT(*) AS unread_total
             FROM app_notifications
             WHERE user_id = :user_id
               AND is_read = 0",
            ['user_id' => $userId]
        );

        return TypeHelper::toInt($row['unread_total'] ?? 0) ?? 0;
    }

    /**
     * Return one user's notifications ordered newest first.
     *
     * @param int $userId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $pdo = self::getPDO();
        $stmt = $pdo->prepare(
            "SELECT id,
                    notification_type,
                    title,
                    message,
                    link_url,
                    is_read,
                    read_at,
                    created_at
             FROM app_notifications
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :offset, :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * Count total notifications for one user.
     *
     * @param int $userId
     * @return int
     */
    public static function countForUser(int $userId): int
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return 0;
        }

        $row = self::fetch(
            "SELECT COUNT(*) AS notification_total
             FROM app_notifications
             WHERE user_id = :user_id",
            ['user_id' => $userId]
        );

        return TypeHelper::toInt($row['notification_total'] ?? 0) ?? 0;
    }

    /**
     * Insert one notification row.
     *
     * @param int $userId
     * @param string $type
     * @param string $title
     * @param string $message
     * @param string $linkUrl
     * @return int
     */
    public static function create(int $userId, string $type, string $title, string $message, string $linkUrl = ''): int
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return 0;
        }

        return (int) self::insert(
            "INSERT INTO app_notifications (
                user_id,
                notification_type,
                title,
                message,
                link_url,
                is_read,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :notification_type,
                :title,
                :message,
                :link_url,
                0,
                NOW(),
                NOW()
             )",
            [
                'user_id' => $userId,
                'notification_type' => trim($type) !== '' ? trim($type) : 'general',
                'title' => trim($title),
                'message' => trim($message),
                'link_url' => trim($linkUrl),
            ]
        );
    }

    /**
     * Mark all notifications as read for one user.
     *
     * @param int $userId
     * @return void
     */
    public static function markAllReadForUser(int $userId): void
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "UPDATE app_notifications
             SET is_read = 1,
                 read_at = NOW(),
                 updated_at = NOW()
             WHERE user_id = :user_id
               AND is_read = 0",
            ['user_id' => $userId]
        );
    }

    /**
     * Mark unread rules-update notifications as read for one user.
     *
     * @param int $userId
     * @return void
     */
    public static function markRulesNotificationsReadForUser(int $userId): void
    {
        if ($userId < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "UPDATE app_notifications
             SET is_read = 1,
                 read_at = NOW(),
                 updated_at = NOW()
             WHERE user_id = :user_id
               AND notification_type = 'rules_update'
               AND is_read = 0",
            ['user_id' => $userId]
        );
    }
}
