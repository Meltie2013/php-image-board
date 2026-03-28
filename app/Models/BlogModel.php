<?php

/**
 * BlogModel
 *
 * Stores staff-authored blog posts and the public discussion attached to each
 * published post.
 */
class BlogModel extends BaseModel
{
    /**
     * Cached schema availability state for the current request.
     *
     * @var bool|null
     */
    private static ?bool $schemaAvailable = null;

    /**
     * Determine whether the blog schema is available.
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
            self::fetch("SELECT id FROM app_blog_posts LIMIT 1");
            self::fetch("SELECT id FROM app_blog_comments LIMIT 1");
            self::$schemaAvailable = true;
        }
        catch (Throwable $e)
        {
            self::$schemaAvailable = false;
        }

        return self::$schemaAvailable;
    }

    /**
     * Count published posts for the public blog landing page.
     *
     * @return int
     */
    public static function countPublishedPosts(): int
    {
        if (!self::isSchemaAvailable())
        {
            return 0;
        }

        $row = self::fetch(
            "SELECT COUNT(*) AS total
             FROM app_blog_posts
             WHERE status = 'published'"
        );

        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of published blog posts.
     *
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public static function fetchPublishedPostsPage(int $limit, int $offset): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        $sql = "SELECT
                    p.id,
                    p.user_id,
                    p.slug,
                    p.title,
                    p.excerpt,
                    p.body,
                    p.allow_comments,
                    p.published_at,
                    p.created_at,
                    p.updated_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username, 'Unknown') AS author_name,
                    u.avatar_path,
                    (
                        SELECT COUNT(*)
                        FROM app_blog_comments c
                        WHERE c.post_id = p.id
                          AND c.is_deleted = 0
                    ) AS comment_count
                FROM app_blog_posts p
                LEFT JOIN app_users u ON p.user_id = u.id
                WHERE p.status = 'published'
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find one published public blog post by slug.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public static function findPublishedPostBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT
                p.id,
                p.user_id,
                p.slug,
                p.title,
                p.excerpt,
                p.body,
                p.status,
                p.allow_comments,
                p.published_at,
                p.created_at,
                p.updated_at,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unknown') AS author_name,
                u.avatar_path,
                u.date_of_birth,
                (
                    SELECT COUNT(*)
                    FROM app_blog_comments c
                    WHERE c.post_id = p.id
                      AND c.is_deleted = 0
                ) AS comment_count
             FROM app_blog_posts p
             LEFT JOIN app_users u ON p.user_id = u.id
             WHERE p.slug = :slug
               AND p.status = 'published'
             LIMIT 1",
            [':slug' => $slug]
        );
    }

    /**
     * Find one post by id for the control panel editor.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findPostById(int $id): ?array
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT
                p.id,
                p.user_id,
                p.slug,
                p.title,
                p.excerpt,
                p.body,
                p.status,
                p.allow_comments,
                p.published_at,
                p.created_at,
                p.updated_at,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unknown') AS author_name
             FROM app_blog_posts p
             LEFT JOIN app_users u ON p.user_id = u.id
             WHERE p.id = :id
             LIMIT 1",
            [':id' => $id]
        );
    }

    /**
     * Find one post by slug across all statuses.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public static function findPostBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !self::isSchemaAvailable())
        {
            return null;
        }

        return self::fetch(
            "SELECT id, slug, status
             FROM app_blog_posts
             WHERE slug = :slug
             LIMIT 1",
            [':slug' => $slug]
        );
    }

    /**
     * Return the control-panel blog post listing.
     *
     * Deleted posts are excluded from the main list after a delete action so the
     * control panel stays focused on actively editable content.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listPostsForAdmin(): array
    {
        if (!self::isSchemaAvailable())
        {
            return [];
        }

        return self::fetchAll(
            "SELECT
                p.id,
                p.slug,
                p.title,
                p.status,
                p.allow_comments,
                p.published_at,
                p.created_at,
                p.updated_at,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unknown') AS author_name,
                (
                    SELECT COUNT(*)
                    FROM app_blog_comments c
                    WHERE c.post_id = p.id
                      AND c.is_deleted = 0
                ) AS comment_count
             FROM app_blog_posts p
             LEFT JOIN app_users u ON p.user_id = u.id
             WHERE p.status <> 'deleted'
             ORDER BY
                CASE p.status
                    WHEN 'published' THEN 1
                    WHEN 'draft' THEN 2
                    WHEN 'hidden' THEN 3
                    ELSE 4
                END ASC,
                COALESCE(p.published_at, p.updated_at, p.created_at) DESC,
                p.id DESC"
        );
    }

    /**
     * Create or update one blog post row.
     *
     * @param array<string, mixed> $data
     * @return int
     */
    public static function savePost(array $data): int
    {
        if (!self::isSchemaAvailable())
        {
            return 0;
        }

        $id = TypeHelper::toInt($data['id'] ?? 0) ?? 0;
        $userId = TypeHelper::toInt($data['user_id'] ?? 0) ?? 0;
        $title = trim(TypeHelper::toString($data['title'] ?? '', allowEmpty: true) ?? '');
        $slug = trim(TypeHelper::toString($data['slug'] ?? '', allowEmpty: true) ?? '');
        $excerpt = trim(TypeHelper::toString($data['excerpt'] ?? '', allowEmpty: true) ?? '');
        $body = trim(TypeHelper::toString($data['body'] ?? '', allowEmpty: true) ?? '');
        $status = strtolower(TypeHelper::toString($data['status'] ?? 'draft', allowEmpty: true) ?? 'draft');
        $allowComments = !empty($data['allow_comments']) ? 1 : 0;

        if (!in_array($status, ['draft', 'published', 'hidden', 'deleted'], true))
        {
            $status = 'draft';
        }

        if ($id > 0)
        {
            self::execute(
                "UPDATE app_blog_posts
                 SET title = :title,
                     slug = :slug,
                     excerpt = :excerpt,
                     body = :body,
                     status = :status,
                     allow_comments = :allow_comments,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    ':id' => $id,
                    ':title' => $title,
                    ':slug' => $slug,
                    ':excerpt' => $excerpt !== '' ? $excerpt : null,
                    ':body' => $body,
                    ':status' => $status,
                    ':allow_comments' => $allowComments,
                ]
            );

            return $id;
        }

        return (int) self::insert(
            "INSERT INTO app_blog_posts (
                user_id,
                slug,
                title,
                excerpt,
                body,
                status,
                allow_comments,
                published_by,
                published_at,
                deleted_by,
                deleted_at,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :slug,
                :title,
                :excerpt,
                :body,
                :status,
                :allow_comments,
                NULL,
                NULL,
                NULL,
                NULL,
                NOW(),
                NOW()
             )",
            [
                ':user_id' => $userId,
                ':slug' => $slug,
                ':title' => $title,
                ':excerpt' => $excerpt !== '' ? $excerpt : null,
                ':body' => $body,
                ':status' => $status,
                ':allow_comments' => $allowComments,
            ]
        );
    }

    /**
     * Publish one blog post.
     *
     * @param int $id
     * @param int|null $publishedBy
     * @return void
     */
    public static function publishPost(int $id, ?int $publishedBy = null): void
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "UPDATE app_blog_posts
             SET status = 'published',
                 published_by = COALESCE(published_by, :published_by),
                 published_at = COALESCE(published_at, NOW()),
                 deleted_by = NULL,
                 deleted_at = NULL,
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $id,
                ':published_by' => $publishedBy,
            ]
        );
    }

    /**
     * Hide one blog post from the public blog pages.
     *
     * @param int $id
     * @return void
     */
    public static function hidePost(int $id): void
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "UPDATE app_blog_posts
             SET status = 'hidden',
                 updated_at = NOW()
             WHERE id = :id",
            [':id' => $id]
        );
    }

    /**
     * Soft delete one blog post.
     *
     * @param int $id
     * @param int|null $deletedBy
     * @return void
     */
    public static function deletePost(int $id, ?int $deletedBy = null): void
    {
        if ($id < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "UPDATE app_blog_posts
             SET status = 'deleted',
                 deleted_by = :deleted_by,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $id,
                ':deleted_by' => $deletedBy,
            ]
        );
    }

    /**
     * Count visible comments for one blog post.
     *
     * @param int $postId
     * @return int
     */
    public static function countVisibleComments(int $postId): int
    {
        if ($postId < 1 || !self::isSchemaAvailable())
        {
            return 0;
        }

        $row = self::fetch(
            "SELECT COUNT(*) AS total
             FROM app_blog_comments
             WHERE post_id = :post_id
               AND is_deleted = 0",
            [':post_id' => $postId]
        );

        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of visible comments for a published post.
     *
     * @param int $postId
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public static function fetchVisibleCommentsPage(int $postId, int $limit, int $offset): array
    {
        if ($postId < 1 || !self::isSchemaAvailable())
        {
            return [];
        }

        $sql = "SELECT
                    c.comment_body,
                    c.created_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username, 'Unknown') AS author_name,
                    u.avatar_path,
                    u.date_of_birth
                FROM app_blog_comments c
                LEFT JOIN app_users u ON c.user_id = u.id
                WHERE c.post_id = :post_id
                  AND c.is_deleted = 0
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert one blog comment row.
     *
     * @param int $postId
     * @param int $userId
     * @param string $commentBody
     * @return void
     */
    public static function insertComment(int $postId, int $userId, string $commentBody): void
    {
        if ($postId < 1 || $userId < 1 || !self::isSchemaAvailable())
        {
            return;
        }

        self::execute(
            "INSERT INTO app_blog_comments (
                post_id,
                user_id,
                comment_body,
                is_deleted,
                deleted_at,
                created_at,
                updated_at
             ) VALUES (
                :post_id,
                :user_id,
                :comment_body,
                0,
                NULL,
                NOW(),
                NOW()
             )",
            [
                ':post_id' => $postId,
                ':user_id' => $userId,
                ':comment_body' => $commentBody,
            ]
        );
    }
}
