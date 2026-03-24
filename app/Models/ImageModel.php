<?php

/**
 * Image, vote, favorite, comment, and hash related database access helpers.
 */
class ImageModel extends BaseModel
{
    /**
     * Build the approved-gallery content filter for one viewer access level.
     *
     * Access levels:
     * - standard_only
     * - sensitive
     * - explicit
     *
     * @param string $viewerContentAccessLevel
     * @return string
     */
    private static function buildApprovedGalleryContentWhereClause(string $viewerContentAccessLevel): string
    {
        $ratingSql = "CASE
            WHEN content_rating IN ('standard', 'sensitive', 'explicit') THEN content_rating
            WHEN age_sensitive = 1 THEN 'sensitive'
            ELSE 'standard'
        END";

        return match ($viewerContentAccessLevel)
        {
            'explicit' => "WHERE status = 'approved'",
            'sensitive' => "WHERE status = 'approved' AND {$ratingSql} IN ('standard', 'sensitive')",
            default => "WHERE status = 'approved' AND {$ratingSql} = 'standard'",
        };
    }

    /**
     * Count gallery images based on viewer access.
     *
     * @param string $viewerContentAccessLevel
     * @return int
     */
    public static function countGalleryImages(string $viewerContentAccessLevel): int
    {
        $where = self::buildApprovedGalleryContentWhereClause($viewerContentAccessLevel);

        $row = self::fetch("SELECT COUNT(*) AS total FROM app_images {$where}");
        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of gallery images.
     *
     * @param string $viewerContentAccessLevel
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function fetchGalleryPage(string $viewerContentAccessLevel, int $limit, int $offset): array
    {
        $where = self::buildApprovedGalleryContentWhereClause($viewerContentAccessLevel);

        $sql = "SELECT image_hash, original_path, mime_type, age_sensitive, content_rating, created_at, views
                FROM app_images
                {$where}
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch one gallery view row.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findApprovedGalleryImageByHash(string $hash): ?array
    {
        if ($hash === '')
        {
            return null;
        }

        return self::fetch(
            "SELECT
                i.id,
                i.user_id,
                i.image_hash,
                i.status,
                i.description,
                i.age_sensitive,
                i.content_rating,
                i.created_at,
                i.mime_type,
                i.width,
                i.height,
                i.size_bytes,
                i.md5,
                i.sha1,
                i.sha256,
                i.sha512,
                i.reject_reason,
                COALESCE(v.votes, 0) AS votes,
                COALESCE(f.favorites, 0) AS favorites,
                COALESCE(r.open_reports, 0) AS open_reports,
                i.views,
                u.date_of_birth,
                u.age_verified_at
             FROM app_images i
             LEFT JOIN app_users u ON i.user_id = u.id
             LEFT JOIN (
                SELECT image_id, COUNT(*) AS favorites
                FROM app_image_favorites
                GROUP BY image_id
             ) f ON i.id = f.image_id
             LEFT JOIN (
                SELECT image_id, COUNT(*) AS votes
                FROM app_image_votes
                GROUP BY image_id
             ) v ON i.id = v.image_id
             LEFT JOIN (
                SELECT image_id, COUNT(*) AS open_reports
                FROM app_image_reports
                WHERE status = 'open'
                GROUP BY image_id
             ) r ON i.id = r.image_id
             WHERE i.image_hash = :hash
               AND i.status = 'approved'
             LIMIT 1",
            [':hash' => $hash]
        );
    }

    /**
     * Increment one image view counter.
     *
     * @param int $imageId
     * @return void
     */
    public static function incrementViews(int $imageId): void
    {
        self::execute("UPDATE app_images SET views = views + 1 WHERE id = :id LIMIT 1", [':id' => $imageId]);
    }

    /**
     * Determine whether one user has voted on one image.
     *
     * @param int $userId
     * @param int $imageId
     * @return bool
     */
    public static function hasUserVote(int $userId, int $imageId): bool
    {
        if ($userId < 1 || $imageId < 1)
        {
            return false;
        }

        return TypeHelper::rowExists(self::fetch(
            "SELECT 1 FROM app_image_votes WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
            [':user_id' => $userId, ':image_id' => $imageId]
        ));
    }

    /**
     * Determine whether one user has favorited one image.
     *
     * @param int $userId
     * @param int $imageId
     * @return bool
     */
    public static function hasUserFavorite(int $userId, int $imageId): bool
    {
        if ($userId < 1 || $imageId < 1)
        {
            return false;
        }

        return TypeHelper::rowExists(self::fetch(
            "SELECT 1 FROM app_image_favorites WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
            [':user_id' => $userId, ':image_id' => $imageId]
        ));
    }

    /**
     * Count visible comments for one image.
     *
     * @param int $imageId
     * @return int
     */
    public static function countVisibleComments(int $imageId): int
    {
        $row = self::fetch(
            "SELECT COUNT(*) AS count
             FROM app_image_comments
             WHERE image_id = :image_id
               AND is_deleted = 0",
            [':image_id' => $imageId]
        );

        return TypeHelper::toInt($row['count'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of visible comments.
     *
     * @param int $imageId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function fetchVisibleCommentsPage(int $imageId, int $limit, int $offset): array
    {
        $sql = "SELECT c.comment_body, c.created_at, u.username, u.date_of_birth
                FROM app_image_comments c
                LEFT JOIN app_users u ON c.user_id = u.id
                WHERE c.image_id = :image_id
                  AND c.is_deleted = 0
                ORDER BY c.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':image_id', $imageId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch live interaction counters for one image.
     *
     * @param int $imageId
     * @return array|null
     */
    public static function getInteractionStatsByImageId(int $imageId): ?array
    {
        return self::fetch(
            "SELECT
                i.image_hash,
                i.views,
                (SELECT COUNT(*) FROM app_image_votes WHERE image_id = i.id) AS votes,
                (SELECT COUNT(*) FROM app_image_favorites WHERE image_id = i.id) AS favorites,
                (SELECT COUNT(*) FROM app_image_comments WHERE image_id = i.id AND is_deleted = 0) AS comments
             FROM app_images i
             WHERE i.id = :image_id
             LIMIT 1",
            [':image_id' => $imageId]
        );
    }

    /**
     * Fetch one approved image id by hash.
     *
     * @param string $hash
     * @return int
     */
    public static function findApprovedImageIdByHash(string $hash): int
    {
        $row = self::fetch(
            "SELECT id FROM app_images WHERE image_hash = :hash AND status = 'approved' LIMIT 1",
            [':hash' => $hash]
        );

        return TypeHelper::toInt($row['id'] ?? 0) ?? 0;
    }

    /**
     * Fetch any image id by hash.
     *
     * @param string $hash
     * @return int
     */
    public static function findImageIdByHash(string $hash): int
    {
        $row = self::fetch("SELECT id FROM app_images WHERE image_hash = :hash LIMIT 1", [':hash' => $hash]);
        return TypeHelper::toInt($row['id'] ?? 0) ?? 0;
    }

    /**
     * Fetch one editable image row by hash.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findEditableImageByHash(string $hash): ?array
    {
        return self::fetch(
            "SELECT id, user_id, image_hash FROM app_images WHERE image_hash = :hash LIMIT 1",
            [':hash' => $hash]
        );
    }

    /**
     * Update one image description.
     *
     * @param string $imageHash
     * @param string $description
     * @return int
     */
    public static function updateDescriptionByHash(string $imageHash, string $description): int
    {
        return self::execute(
            "UPDATE app_images
             SET description = :description,
                 updated_at = NOW()
             WHERE image_hash = :image_hash
             LIMIT 1",
            [':description' => $description, ':image_hash' => $imageHash]
        );
    }

    /**
     * Insert one favorite row.
     *
     * @param int $userId
     * @param int $imageId
     * @return void
     */
    public static function insertFavorite(int $userId, int $imageId): void
    {
        self::execute(
            "INSERT INTO app_image_favorites (user_id, image_id) VALUES (:user_id, :image_id)",
            [':user_id' => $userId, ':image_id' => $imageId]
        );
    }

    /**
     * Insert one vote row.
     *
     * @param int $userId
     * @param int $imageId
     * @return void
     */
    public static function insertVote(int $userId, int $imageId): void
    {
        self::execute(
            "INSERT INTO app_image_votes (user_id, image_id) VALUES (:user_id, :image_id)",
            [':user_id' => $userId, ':image_id' => $imageId]
        );
    }

    /**
     * Insert one comment row.
     *
     * @param int $imageId
     * @param int $userId
     * @param string $commentBody
     * @return void
     */
    public static function insertComment(int $imageId, int $userId, string $commentBody): void
    {
        self::execute(
            "INSERT INTO app_image_comments (image_id, user_id, comment_body)
             VALUES (:image_id, :user_id, :comment_body)",
            [
                ':image_id' => $imageId,
                ':user_id' => $userId,
                ':comment_body' => $commentBody,
            ]
        );
    }

    /**
     * Fetch one approved image row for original-file serving.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findApprovedServableImageByHash(string $hash): ?array
    {
        return self::fetch(
            "SELECT original_path, mime_type, age_sensitive, content_rating
             FROM app_images
             WHERE image_hash = :hash
               AND status = 'approved'
             LIMIT 1",
            [':hash' => $hash]
        );
    }

    /**
     * Return dashboard image counters.
     *
     * @return array
     */
    public static function getDashboardCounts(): array
    {
        return [
            'total' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status IN ('approved', 'pending', 'deleted', 'rejected')")['cnt'] ?? 0)) ?? 0,
            'approved' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'approved'")['cnt'] ?? 0)) ?? 0,
            'pending' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'")['cnt'] ?? 0)) ?? 0,
            'removed' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'deleted'")['cnt'] ?? 0)) ?? 0,
            'rejected' => TypeHelper::toInt((self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'rejected'")['cnt'] ?? 0)) ?? 0,
            'views' => TypeHelper::toInt((self::fetch("SELECT COALESCE(SUM(views), 0) AS total_views FROM app_images WHERE status = 'approved'")['total_views'] ?? 0)) ?? 0,
        ];
    }

    /**
     * Count pending images.
     *
     * @return int
     */
    public static function countPendingImages(): int
    {
        $row = self::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'");
        return TypeHelper::toInt($row['cnt'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of pending images.
     *
     * @param int $offset
     * @param int $perPage
     * @return array
     */
    public static function fetchPendingImagesPage(int $offset, int $perPage): array
    {
        $sql = "SELECT
                    i.image_hash,
                    i.age_sensitive,
                    i.content_rating,
                    i.mime_type,
                    i.width,
                    i.height,
                    i.size_bytes,
                    i.created_at,
                    COALESCE(u.username, 'Unknown') AS username
                FROM app_images i
                LEFT JOIN app_users u ON i.user_id = u.id
                WHERE i.status = 'pending'
                ORDER BY i.created_at DESC
                LIMIT :offset, :perpage";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':perpage', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch one moderation status row by hash.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findModerationStatusByHash(string $hash): ?array
    {
        return self::fetch(
            "SELECT image_hash, status
             FROM app_images
             WHERE image_hash = :hash
             LIMIT 1",
            [':hash' => $hash]
        );
    }

    /**
     * Approve one pending image.
     *
     * @param string $hash
     * @param int $approvedBy
     * @param string $contentRating
     * @return int
     */
    public static function approvePendingImage(string $hash, int $approvedBy, string $contentRating = 'standard'): int
    {
        $contentRating = AgeGateHelper::normalizeContentRating($contentRating);

        return self::execute(
            "UPDATE app_images
             SET age_sensitive = :age_sensitive,
                 content_rating = :content_rating,
                 status = 'approved',
                 approved_by = :approved_by,
                 moderated_at = NOW(),
                 updated_at = NOW()
             WHERE image_hash = :hash
               AND status = 'pending'",
            [
                ':age_sensitive' => $contentRating === 'standard' ? 0 : 1,
                ':content_rating' => $contentRating,
                ':approved_by' => $approvedBy,
                ':hash' => $hash,
            ]
        );
    }

    /**
     * Reject one pending image.
     *
     * @param string $hash
     * @param int $rejectedBy
     * @return int
     */
    public static function rejectPendingImage(string $hash, int $rejectedBy, string $rejectReason = ''): int
    {
        return self::execute(
            "UPDATE app_images
             SET status = 'rejected',
                 rejected_by = :rejected_by,
                 reject_reason = :reject_reason,
                 moderated_at = NOW(),
                 updated_at = NOW()
             WHERE image_hash = :hash
               AND status = 'pending'",
            [
                ':rejected_by' => $rejectedBy,
                ':reject_reason' => $rejectReason,
                ':hash' => $hash,
            ]
        );
    }


    /**
     * Fetch one full pending image row for moderation review.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findPendingModerationImageByHash(string $hash): ?array
    {
        return self::fetch(
            "SELECT
                id,
                user_id,
                image_hash,
                description,
                status,
                reject_reason,
                age_sensitive,
                content_rating,
                mime_type,
                original_path,
                width,
                height,
                size_bytes,
                md5,
                sha1,
                sha256,
                sha512,
                created_at,
                updated_at
             FROM app_images
             WHERE image_hash = :hash
               AND status = 'pending'
             LIMIT 1",
            [':hash' => $hash]
        );
    }

    /**
     * Save pending-image moderation draft fields without approving or rejecting.
     *
     * @param string $hash
     * @param string $description
     * @param string $contentRating
     * @return int
     */
    public static function updatePendingModerationDraft(string $hash, string $description, string $contentRating): int
    {
        $contentRating = AgeGateHelper::normalizeContentRating($contentRating);

        return self::execute(
            "UPDATE app_images
             SET description = :description,
                 age_sensitive = :age_sensitive,
                 content_rating = :content_rating,
                 updated_at = NOW()
             WHERE image_hash = :hash
               AND status = 'pending'",
            [
                ':description' => $description,
                ':age_sensitive' => $contentRating === 'standard' ? 0 : 1,
                ':content_rating' => $contentRating,
                ':hash' => $hash,
            ]
        );
    }

    /**
     * List approved image hashes for moderation tools.
     *
     * @return array
     */
    public static function listApprovedImageHashes(): array
    {
        return self::fetchAll("SELECT image_hash FROM app_images WHERE status IN ('approved') ORDER BY id DESC");
    }

    /**
     * Fetch one image hash row.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findImageHashRow(string $hash): ?array
    {
        return self::fetch("SELECT * FROM app_image_hashes WHERE image_hash = :hash LIMIT 1", ['hash' => $hash]);
    }

    /**
     * Fetch one approved image row.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findApprovedImageByHash(string $hash): ?array
    {
        return self::fetch("SELECT * FROM app_images WHERE image_hash = :hash AND status IN ('approved') LIMIT 1", ['hash' => $hash]);
    }

    /**
     * Fetch a small batch of images waiting for hash refresh.
     *
     * @param int $limit
     * @return array
     */
    public static function fetchImagesPendingRehash(int $limit = 10): array
    {
        $sql = "SELECT *
                FROM app_images
                WHERE (rehashed = 0 OR rehashed_on IS NULL)
                  AND status IN ('approved')
                ORDER BY id ASC
                LIMIT :limit";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Upsert one image hash record.
     *
     * @param array $payload
     * @return void
     */
    public static function upsertImageHashes(array $payload): void
    {
        self::execute(
            "INSERT INTO app_image_hashes
                (image_hash, ahash, dhash, phash, phash_block_0, phash_block_1, phash_block_2, phash_block_3,
                 phash_block_4, phash_block_5, phash_block_6, phash_block_7,
                 phash_block_8, phash_block_9, phash_block_10, phash_block_11,
                 phash_block_12, phash_block_13, phash_block_14, phash_block_15)
             VALUES
                (:image_hash, :ahash, :dhash, :phash,
                 :phash_block_0, :phash_block_1, :phash_block_2, :phash_block_3,
                 :phash_block_4, :phash_block_5, :phash_block_6, :phash_block_7,
                 :phash_block_8, :phash_block_9, :phash_block_10, :phash_block_11,
                 :phash_block_12, :phash_block_13, :phash_block_14, :phash_block_15)
             ON DUPLICATE KEY UPDATE
                ahash = VALUES(ahash),
                dhash = VALUES(dhash),
                phash = VALUES(phash),
                phash_block_0 = VALUES(phash_block_0),
                phash_block_1 = VALUES(phash_block_1),
                phash_block_2 = VALUES(phash_block_2),
                phash_block_3 = VALUES(phash_block_3),
                phash_block_4 = VALUES(phash_block_4),
                phash_block_5 = VALUES(phash_block_5),
                phash_block_6 = VALUES(phash_block_6),
                phash_block_7 = VALUES(phash_block_7),
                phash_block_8 = VALUES(phash_block_8),
                phash_block_9 = VALUES(phash_block_9),
                phash_block_10 = VALUES(phash_block_10),
                phash_block_11 = VALUES(phash_block_11),
                phash_block_12 = VALUES(phash_block_12),
                phash_block_13 = VALUES(phash_block_13),
                phash_block_14 = VALUES(phash_block_14),
                phash_block_15 = VALUES(phash_block_15)",
            $payload
        );
    }

    /**
     * Mark one image as rehashed.
     *
     * @param int $imageId
     * @return void
     */
    public static function markImageRehashed(int $imageId): void
    {
        self::execute(
            "UPDATE app_images
             SET rehashed = 1,
                 rehashed_on = NOW()
             WHERE id = :id",
            ['id' => $imageId]
        );
    }

    /**
     * Fetch one pending image row for preview serving.
     *
     * @param string $hash
     * @return array|null
     */
    public static function findPendingServableImageByHash(string $hash): ?array
    {
        return self::fetch(
            "SELECT original_path, mime_type, age_sensitive, content_rating
             FROM app_images
             WHERE image_hash = :hash
               AND status = 'pending'
             LIMIT 1",
            ['hash' => $hash]
        );
    }

    /**
     * Apply one image action selected from report review.
     *
     * @param int $imageId
     * @param string $action
     * @return void
     */
    public static function applyReportImageAction(int $imageId, string $action): void
    {
        if ($imageId < 1)
        {
            return;
        }

        switch ($action)
        {
            case 'set_standard':
                self::execute("UPDATE app_images SET age_sensitive = 0, content_rating = 'standard', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_sensitive':
                self::execute("UPDATE app_images SET age_sensitive = 1, content_rating = 'sensitive', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_explicit':
                self::execute("UPDATE app_images SET age_sensitive = 1, content_rating = 'explicit', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_pending':
                self::execute("UPDATE app_images SET status = 'pending', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_approved':
                self::execute("UPDATE app_images SET status = 'approved', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_rejected':
                self::execute("UPDATE app_images SET status = 'rejected', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;

            case 'set_deleted':
                self::execute("UPDATE app_images SET status = 'deleted', updated_at = NOW() WHERE id = :image_id", [':image_id' => $imageId]);
                break;
        }
    }

    /**
     * Return existing image hashes from one candidate list.
     *
     * @param array $hashes
     * @return array
     */
    public static function findExistingHashes(array $hashes): array
    {
        if (empty($hashes))
        {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        return self::fetchAll("SELECT image_hash FROM app_images WHERE image_hash IN ({$placeholders})", array_values($hashes));
    }


    /**
     * Insert one upload audit log row.
     *
     * @param array $payload
     * @return void
     */
    public static function logUploadAttempt(array $payload): void
    {
        self::insert(
            "INSERT INTO app_image_upload_logs
                (user_id, ip_address, filename_original, mime_reported, mime_detected,
                 file_extension, file_size, status, failure_reason, stored_path, created_at)
             VALUES
                (:user_id, :ip_address, :filename_original, :mime_reported, :mime_detected,
                 :file_extension, :file_size, :status, :failure_reason, :stored_path, NOW())",
            $payload
        );
    }

    /**
     * Insert one uploaded image row.
     *
     * @param array $payload
     * @param bool $autoApprove
     * @return void
     */
    public static function createUploadedImage(array $payload, bool $autoApprove): void
    {
        $moderatedValue = $autoApprove ? 'NOW()' : 'NULL';

        self::insert(
            "INSERT INTO app_images
                (image_hash, user_id, description, status,
                 original_path, mime_type, width, height, size_bytes,
                 md5, sha1, sha256, sha512, moderated_at, created_at, updated_at)
             VALUES
                (:image_hash, :user_id, :description, :status,
                 :original_path, :mime_type, :width, :height, :size_bytes,
                 :md5, :sha1, :sha256, :sha512, {$moderatedValue}, NOW(), NOW())",
            $payload
        );
    }

    /**
     * Insert one image hash row generated during upload.
     *
     * @param array $payload
     * @return void
     */
    public static function createImageHashes(array $payload): void
    {
        self::insert(
            "INSERT INTO app_image_hashes
                (image_hash, phash, ahash, dhash,
                 phash_block_0, phash_block_1, phash_block_2, phash_block_3,
                 phash_block_4, phash_block_5, phash_block_6, phash_block_7,
                 phash_block_8, phash_block_9, phash_block_10, phash_block_11,
                 phash_block_12, phash_block_13, phash_block_14, phash_block_15)
             VALUES
                (:image_hash, :phash, :ahash, :dhash,
                 :phash_block_0, :phash_block_1, :phash_block_2, :phash_block_3,
                 :phash_block_4, :phash_block_5, :phash_block_6, :phash_block_7,
                 :phash_block_8, :phash_block_9, :phash_block_10, :phash_block_11,
                 :phash_block_12, :phash_block_13, :phash_block_14, :phash_block_15)",
            $payload
        );
    }

}
