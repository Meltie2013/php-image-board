<?php

/**
 * BlogController
 *
 * Handles the public blog landing page, individual published posts, and the
 * blog comment workflow.
 */
class BlogController extends BaseController
{
    /**
     * Shared template variables for the public blog pages.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
    ];

    /**
     * Blog pages can render comment forms and staff actions.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Render the public blog landing page.
     *
     * @param int|null $page
     * @return void
     */
    public static function index($page = null): void
    {
        $config = self::getConfig();
        $template = self::initTemplate();
        $page = self::normalizeBlogPageNumber($page ?? 1);
        $postsPerPage = self::resolveBlogPostsPerPage($config);
        $totalCount = BlogModel::countPublishedPosts();
        $totalPages = max(1, (int) ceil($totalCount / $postsPerPage));
        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $postsPerPage;
        $posts = BlogModel::fetchPublishedPostsPage($postsPerPage, $offset);
        $pagination = self::buildBlogPaginationState($page, $totalPages);

        $template->assign('blog_posts', self::buildBlogPreviewRows($posts));
        $template->assign('blog_total_posts', $totalCount);
        $template->assign('pagination_prev', $pagination['pagination_prev']);
        $template->assign('pagination_next', $pagination['pagination_next']);
        $template->assign('pagination_pages', $pagination['pagination_pages']);
        $template->render('blog/blog_index.html');
    }

    /**
     * Render one published blog post page.
     *
     * @param string $slug
     * @return void
     */
    public static function view(string $slug): void
    {
        $config = self::getConfig();
        $template = self::initTemplate();
        $slug = trim(TypeHelper::toString($slug, allowEmpty: true) ?? '');
        if ($slug === '')
        {
            self::renderErrorPage(404, 'Blog Post Not Found', 'The requested blog post could not be found.', $template);
            return;
        }

        $post = BlogModel::findPublishedPostBySlug($slug);
        if (!$post)
        {
            self::renderErrorPage(404, 'Blog Post Not Found', 'The requested blog post could not be found.', $template);
            return;
        }

        $commentsPerPage = self::resolveBlogCommentsPerPage($config);
        $commentsPage = self::normalizeBlogPageNumber($_GET['cpage'] ?? 1);
        $commentState = self::resolveBlogCommentState(TypeHelper::toInt($post['id'] ?? 0) ?? 0, $commentsPerPage, $commentsPage, $slug, $config);
        $permissionState = self::resolveBlogCommentPermissionState();
        $authorName = TypeHelper::toString(ucfirst($post['author_name']) ?? 'Unknown');

        $template->assign('blog_post_id', TypeHelper::toInt($post['id'] ?? 0) ?? 0);
        $template->assign('blog_post_slug', TypeHelper::toString($post['slug'] ?? ''));
        $template->assign('blog_post_title', TypeHelper::toString($post['title'] ?? ''));
        $template->assign('blog_post_excerpt_html', self::formatBlogBodyHtml(TypeHelper::toString($post['excerpt'] ?? '', allowEmpty: true) ?? ''));
        $template->assign('blog_post_body_html', self::formatBlogBodyHtml(TypeHelper::toString($post['body'] ?? '', allowEmpty: true) ?? ''));
        $template->assign('blog_post_author_name', $authorName);
        $template->assign('blog_post_author_initial', self::buildDisplayInitial($authorName));
        $template->assign('blog_post_author_avatar', TypeHelper::toString($post['avatar_path'] ?? '', allowEmpty: true) ?? '');
        $template->assign('blog_post_author_has_birthday_badge', AgeGateHelper::shouldShowBirthdayBadge($post['date_of_birth'] ?? null, $config) ? 1 : 0);
        $template->assign('blog_post_created_at', DateHelper::format(TypeHelper::toString($post['created_at'] ?? '', allowEmpty: true)));
        $template->assign('blog_post_updated_at', DateHelper::format(TypeHelper::toString($post['updated_at'] ?? '', allowEmpty: true)));
        $template->assign('blog_post_published_at', DateHelper::format(TypeHelper::toString($post['published_at'] ?? '', allowEmpty: true)));
        $template->assign('blog_post_allow_comments', !empty($post['allow_comments']) ? 1 : 0);
        $template->assign('blog_post_comment_count', TypeHelper::toInt($post['comment_count'] ?? 0) ?? 0);

        foreach ($commentState + $permissionState as $key => $value)
        {
            $template->assign($key, $value);
        }

        $template->render('blog/blog_view.html');
    }

    /**
     * Handle posting a comment on a published blog post.
     *
     * @param string $slug
     * @return void
     */
    public static function comment(string $slug): void
    {
        $template = self::initTemplate();
        $userId = self::requireAuthenticatedUserId(true);

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            self::renderInvalidRequest($template);
            return;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        if (!GroupPermissionHelper::hasPermission('comment_blog_posts'))
        {
            self::renderErrorPage(403, 'Access Denied', 'You do not have permission to comment on blog posts.', $template);
            return;
        }

        $slug = trim(TypeHelper::toString($slug, allowEmpty: true) ?? '');
        if ($slug === '')
        {
            self::renderErrorPage(404, 'Blog Post Not Found', 'The requested blog post could not be found.', $template);
            return;
        }

        $post = BlogModel::findPublishedPostBySlug($slug);
        if (!$post)
        {
            self::renderErrorPage(404, 'Blog Post Not Found', 'The requested blog post could not be found.', $template);
            return;
        }

        if (empty($post['allow_comments']))
        {
            self::redirectToBlogPost($slug);
        }

        $commentBody = Security::sanitizeString($_POST['comment_body'] ?? '');
        if ($commentBody === '')
        {
            self::redirectToBlogPost($slug);
        }

        $postId = TypeHelper::toInt($post['id'] ?? 0) ?? 0;
        if ($postId < 1)
        {
            self::renderErrorPage(404, 'Blog Post Not Found', 'The requested blog post could not be found.', $template);
            return;
        }

        BlogModel::insertComment($postId, $userId, $commentBody);
        self::redirectToBlogPost($slug);
    }

    /**
     * Resolve the configured public blog posts page size.
     *
     * @param array $config
     * @return int
     */
    private static function resolveBlogPostsPerPage(array $config): int
    {
        $postsPerPage = TypeHelper::toInt($config['blog']['posts_per_page'] ?? null) ?? 10;
        return $postsPerPage > 0 ? $postsPerPage : 10;
    }

    /**
     * Resolve the configured blog comments page size.
     *
     * @param array $config
     * @return int
     */
    private static function resolveBlogCommentsPerPage(array $config): int
    {
        $commentsPerPage = TypeHelper::toInt($config['blog']['comments_per_page'] ?? null) ?? 10;
        return $commentsPerPage > 0 ? $commentsPerPage : 10;
    }

    /**
     * Normalize a requested blog page number.
     *
     * @param mixed $value
     * @return int
     */
    private static function normalizeBlogPageNumber(mixed $value): int
    {
        $page = TypeHelper::toInt($value) ?? 1;
        return $page > 0 ? $page : 1;
    }

    /**
     * Build public blog pagination assignments.
     *
     * @param int $page
     * @param int $totalPages
     * @return array{pagination_prev: string|null, pagination_next: string|null, pagination_pages: array<int, array{0: string|null, 1: int|string, 2: bool}>}
     */
    private static function buildBlogPaginationState(int $page, int $totalPages): array
    {
        $paginationPrev = $page > 1 ? ($page - 1 === 1 ? '/blog' : '/blog/page/' . ($page - 1)) : null;
        $paginationNext = $page < $totalPages ? '/blog/page/' . ($page + 1) : null;
        $range = TypeHelper::toInt(SettingsManager::get('gallery.pagination_range', 3)) ?? 3;
        if ($range < 1)
        {
            $range = 3;
        }

        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);
        $paginationPages = [];

        if ($start > 1)
        {
            $paginationPages[] = ['/blog', 1, false];
            if ($start > 2)
            {
                $paginationPages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $paginationPages[] = [
                $i === 1 ? '/blog' : '/blog/page/' . $i,
                $i,
                $i === $page,
            ];
        }

        if ($end < $totalPages)
        {
            if ($end < $totalPages - 1)
            {
                $paginationPages[] = [null, '...', false];
            }
            $paginationPages[] = ['/blog/page/' . $totalPages, $totalPages, false];
        }

        return [
            'pagination_prev' => $paginationPrev,
            'pagination_next' => $paginationNext,
            'pagination_pages' => $paginationPages,
        ];
    }

    /**
     * Build template-ready blog preview rows for the public index.
     *
     * @param array<int, array<string, mixed>> $posts
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: string, 8: string}>
     */
    private static function buildBlogPreviewRows(array $posts): array
    {
        $rows = [];

        foreach ($posts as $post)
        {
            $authorName = TypeHelper::toString(ucfirst($post['author_name']) ?? 'Unknown');
            $excerpt = trim(TypeHelper::toString($post['excerpt'] ?? '', allowEmpty: true) ?? '');
            $body = trim(TypeHelper::toString($post['body'] ?? '', allowEmpty: true) ?? '');
            if ($excerpt === '')
            {
                $excerpt = preg_replace('/\s+/', ' ', $body) ?? '';
                if (strlen($excerpt) > 240)
                {
                    $excerpt = substr($excerpt, 0, 237) . '...';
                }
            }

            $rows[] = [
                TypeHelper::toString($post['slug'] ?? ''),
                TypeHelper::toString($post['title'] ?? ''),
                self::formatBlogBodyHtml($excerpt),
                $authorName,
                self::buildDisplayInitial($authorName),
                TypeHelper::toString($post['avatar_path'] ?? '', allowEmpty: true) ?? '',
                TypeHelper::toInt($post['comment_count'] ?? 0) ?? 0,
                DateHelper::format(TypeHelper::toString($post['published_at'] ?? '', allowEmpty: true)),
                DateHelper::format(TypeHelper::toString($post['updated_at'] ?? '', allowEmpty: true)),
            ];
        }

        return $rows;
    }

    /**
     * Resolve paginated comment data for one blog post.
     *
     * @param int $postId
     * @param int $commentsPerPage
     * @param int $commentsPage
     * @param string $slug
     * @param array $config
     * @return array<string, mixed>
     */
    private static function resolveBlogCommentState(int $postId, int $commentsPerPage, int $commentsPage, string $slug, array $config): array
    {
        $commentCount = BlogModel::countVisibleComments($postId);
        $commentsTotalPages = max(1, (int) ceil($commentCount / $commentsPerPage));
        if ($commentsPage > $commentsTotalPages)
        {
            $commentsPage = $commentsTotalPages;
        }

        $commentsOffset = ($commentsPage - 1) * $commentsPerPage;
        $comments = BlogModel::fetchVisibleCommentsPage($postId, $commentsPerPage, $commentsOffset);

        return [
            'comment_rows' => self::buildBlogCommentRows($comments, $config),
            'comments_page' => $commentsPage,
            'comments_total_pages' => $commentsTotalPages,
            'comments_has_prev' => $commentsPage > 1,
            'comments_has_next' => $commentsPage < $commentsTotalPages,
            'comments_prev_url' => '/blog/' . $slug . '?cpage=' . ($commentsPage - 1) . '#blog-comments',
            'comments_next_url' => '/blog/' . $slug . '?cpage=' . ($commentsPage + 1) . '#blog-comments',
        ];
    }

    /**
     * Normalize comment rows for the public blog view template.
     *
     * @param array<int, array<string, mixed>> $comments
     * @param array $config
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>
     */
    private static function buildBlogCommentRows(array $comments, array $config): array
    {
        $rows = [];

        foreach ($comments as $comment)
        {
            $authorName = TypeHelper::toString(ucfirst($comment['author_name']) ?? 'Unknown');
            $rows[] = [
                $authorName,
                self::buildDisplayInitial($authorName),
                TypeHelper::toString($comment['avatar_path'] ?? '', allowEmpty: true) ?? '',
                DateHelper::format(TypeHelper::toString($comment['created_at'] ?? '', allowEmpty: true)),
                self::formatBlogBodyHtml(TypeHelper::toString($comment['comment_body'] ?? '', allowEmpty: true) ?? ''),
                AgeGateHelper::shouldShowBirthdayBadge($comment['date_of_birth'] ?? null, $config) ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * Resolve the comment permission state for the current viewer.
     *
     * @return array<string, mixed>
     */
    private static function resolveBlogCommentPermissionState(): array
    {
        $userId = self::getCurrentUserId();
        $canComment = $userId > 0 && GroupPermissionHelper::hasPermission('comment_blog_posts');

        return [
            'can_comment_blog' => $canComment ? 1 : 0,
            'comment_blog_permission_message' => $userId > 0
                ? 'You do not have permission to comment on blog posts.'
                : 'You must be logged in to comment on blog posts.',
        ];
    }

    /**
     * Convert plain-text blog body content into display-safe HTML paragraphs.
     *
     * @param string $value
     * @return string
     */
    private static function formatBlogBodyHtml(string $value): string
    {
        $value = trim($value);
        if ($value === '')
        {
            return '';
        }

        return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Build one single-character display initial for avatar placeholders.
     *
     * @param string $value
     * @return string
     */
    private static function buildDisplayInitial(string $value): string
    {
        $value = trim($value);
        if ($value === '')
        {
            return 'U';
        }

        return strtoupper(substr($value, 0, 1));
    }

    /**
     * Redirect back to one blog post page anchored to the comments section.
     *
     * @param string $slug
     * @return void
     */
    private static function redirectToBlogPost(string $slug): void
    {
        header('Location: /blog/' . $slug . '#blog-comments');
        exit();
    }
}
