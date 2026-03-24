<?php

/**
 * Image report and staff-note data access helpers.
 */
class ImageReportModel extends BaseModel
{
    /**
     * Count open image reports.
     *
     * @return int
     */
    public static function countOpen(): int
    {
        $row = self::fetch("SELECT COUNT(*) AS cnt FROM app_image_reports WHERE status = 'open'") ?? [];
        return TypeHelper::toInt($row['cnt'] ?? 0) ?? 0;
    }

    /**
     * Check whether one open duplicate report already exists.
     *
     * @param int $imageId
     * @param int|null $reporterUserId
     * @param string $sessionId
     * @return bool
     */
    public static function hasOpenDuplicate(int $imageId, ?int $reporterUserId, string $sessionId): bool
    {
        $duplicate = self::fetch(
            "SELECT id
             FROM app_image_reports
             WHERE image_id = :image_id
               AND status = 'open'
               AND ((reporter_user_id IS NOT NULL AND reporter_user_id = :reporter_user_id)
                 OR (:session_id_check != '' AND session_id = :session_id_match))
             LIMIT 1",
            [
                ':image_id' => $imageId,
                ':reporter_user_id' => $reporterUserId,
                ':session_id_check' => $sessionId,
                ':session_id_match' => $sessionId,
            ]
        );

        return TypeHelper::rowExists($duplicate);
    }

    /**
     * Insert one public image report.
     *
     * @param array $payload
     * @return void
     */
    public static function create(array $payload): void
    {
        self::execute(
            "INSERT INTO app_image_reports
                (image_id, reporter_user_id, report_category, report_subject, report_message, status, session_id, ip, ua, created_at, updated_at)
             VALUES
                (:image_id, :reporter_user_id, :report_category, :report_subject, :report_message, 'open', :session_id, :ip, :ua, NOW(), NOW())",
            $payload
        );
    }

    /**
     * Return report queue counters.
     *
     * @return array
     */
    public static function getQueueCounts(): array
    {
        return [
            'total' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_image_reports")['cnt'] ?? 0)) ?? 0,
            'open' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_image_reports WHERE status = 'open'")['cnt'] ?? 0)) ?? 0,
            'closed' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_image_reports WHERE status = 'closed'")['cnt'] ?? 0)) ?? 0,
        ];
    }

    /**
     * Fetch one page of report queue rows.
     *
     * @param int $perPage
     * @param int $offset
     * @return array
     */
    public static function fetchQueuePage(int $perPage, int $offset): array
    {
        $sql = "SELECT
                    r.id,
                    r.reporter_user_id,
                    r.report_category,
                    r.report_subject,
                    r.status,
                    r.created_at,
                    r.assigned_to_user_id,
                    i.image_hash,
                    au.username AS assigned_username
                FROM app_image_reports r
                INNER JOIN app_images i ON i.id = r.image_id
                LEFT JOIN app_users au ON au.id = r.assigned_to_user_id
                ORDER BY FIELD(r.status, 'open', 'closed'), r.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch one report detail row.
     *
     * @param int $id
     * @return array|null
     */
    public static function findDetailedById(int $id): ?array
    {
        return self::fetch(
            "SELECT
                r.id,
                r.image_id,
                r.reporter_user_id,
                r.report_category,
                r.report_subject,
                r.report_message,
                r.status,
                r.session_id,
                r.ip,
                r.ua,
                r.assigned_to_user_id,
                r.assigned_at,
                r.resolved_by,
                r.resolved_at,
                r.created_at,
                r.updated_at,
                i.image_hash,
                i.status AS image_status,
                i.age_sensitive,
                i.content_rating,
                i.created_at AS image_created_at,
                au.username AS assigned_username
             FROM app_image_reports r
             INNER JOIN app_images i ON i.id = r.image_id
             LEFT JOIN app_users au ON au.id = r.assigned_to_user_id
             WHERE r.id = :id
             LIMIT 1",
            [':id' => $id]
        );
    }

    /**
     * Fetch staff note rows for one report.
     *
     * @param int $reportId
     * @return array
     */
    public static function fetchCommentsByReportId(int $reportId): array
    {
        return self::fetchAll(
            "SELECT
                c.comment_body,
                c.created_at,
                c.updated_at,
                c.user_id,
                u.username
             FROM app_image_report_comments c
             LEFT JOIN app_users u ON u.id = c.user_id
             WHERE c.report_id = :report_id
             ORDER BY c.created_at DESC, c.id DESC",
            [':report_id' => $reportId]
        );
    }

    /**
     * Fetch minimal report workflow row.
     *
     * @param int $id
     * @return array|null
     */
    public static function findWorkflowRowById(int $id): ?array
    {
        return self::fetch(
            "SELECT id, image_id, status
             FROM app_image_reports
             WHERE id = :id
             LIMIT 1",
            [':id' => $id]
        );
    }

    /**
     * Assign one report to a staff member while it is open.
     *
     * @param int $id
     * @param int $staffUserId
     * @return void
     */
    public static function assignOpenReport(int $id, int $staffUserId): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET assigned_to_user_id = :assigned_user_id,
                 assigned_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND status = 'open'",
            [
                ':id' => $id,
                ':assigned_user_id' => $staffUserId,
            ]
        );
    }

    /**
     * Assign one report to the current reviewer only if it changed.
     *
     * @param int $id
     * @param int $staffUserId
     * @return void
     */
    public static function takeAssignmentIfOpen(int $id, int $staffUserId): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET assigned_to_user_id = :staff_user_id,
                 assigned_at = CASE WHEN assigned_to_user_id IS NULL OR assigned_to_user_id != :staff_user_id_check THEN NOW() ELSE assigned_at END,
                 updated_at = NOW()
             WHERE id = :id
               AND status = 'open'",
            [
                ':id' => $id,
                ':staff_user_id' => $staffUserId,
                ':staff_user_id_check' => $staffUserId,
            ]
        );
    }

    /**
     * Clear report assignment while the report is open.
     *
     * @param int $id
     * @return void
     */
    public static function releaseOpenAssignment(int $id): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET assigned_to_user_id = NULL,
                 assigned_at = NULL,
                 updated_at = NOW()
             WHERE id = :id
               AND status = 'open'",
            [':id' => $id]
        );
    }

    /**
     * Insert one staff comment row.
     *
     * @param int $reportId
     * @param int|null $userId
     * @param string $commentBody
     * @return void
     */
    public static function addComment(int $reportId, ?int $userId, string $commentBody): void
    {
        self::execute(
            "INSERT INTO app_image_report_comments
                (report_id, user_id, comment_body, created_at, updated_at)
             VALUES
                (:report_id, :user_id, :comment_body, NOW(), NOW())",
            [
                ':report_id' => $reportId,
                ':user_id' => $userId,
                ':comment_body' => $commentBody,
            ]
        );
    }

    /**
     * Close one report.
     *
     * @param int $id
     * @param int|null $staffUserId
     * @return void
     */
    public static function close(int $id, ?int $staffUserId): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET status = 'closed',
                 resolved_by = :staff_user_id,
                 resolved_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $id,
                ':staff_user_id' => $staffUserId,
            ]
        );
    }

    /**
     * Reopen one report.
     *
     * @param int $id
     * @return void
     */
    public static function reopen(int $id): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET status = 'open',
                 resolved_by = NULL,
                 resolved_at = NULL,
                 updated_at = NOW()
             WHERE id = :id",
            [':id' => $id]
        );
    }

    /**
     * Touch report updated_at after note/image changes.
     *
     * @param int $id
     * @return void
     */
    public static function touch(int $id): void
    {
        self::execute(
            "UPDATE app_image_reports
             SET updated_at = NOW()
             WHERE id = :id",
            [':id' => $id]
        );
    }
}
