<?php

/**
 * NotificationsController
 *
 * Handles the standalone community notifications inbox.
 */
class NotificationsController extends BaseController
{
    /**
     * Shared template variables for the notifications page.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
    ];

    /**
     * Notifications pages render state-changing forms.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Normalize the requested notifications page number.
     *
     * @param int|null $page Requested page number.
     * @param int $totalPages Total available pages.
     * @return int
     */
    private static function normalizeNotificationsPage(?int $page, int $totalPages): int
    {
        $page = max(1, TypeHelper::toInt($page) ?? 1);
        return min($page, max(1, $totalPages));
    }

    /**
     * Build one normalized notifications template row list.
     *
     * @param array<int, array<string, mixed>> $rows Raw notification rows.
     * @return array<int, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: int, 6: string}>
     */
    private static function buildNotificationRows(array $rows): array
    {
        $notificationRows = [];
        foreach ($rows as $row)
        {
            $notificationRows[] = [
                TypeHelper::toInt($row['id'] ?? 0) ?? 0,
                TypeHelper::toString($row['notification_type'] ?? ''),
                TypeHelper::toString($row['title'] ?? ''),
                TypeHelper::toString($row['message'] ?? ''),
                TypeHelper::toString($row['link_url'] ?? ''),
                !empty($row['is_read']) ? 1 : 0,
                DateHelper::format(TypeHelper::toString($row['created_at'] ?? '', allowEmpty: true)),
            ];
        }

        return $notificationRows;
    }

    /**
     * Build pagination state for the notifications inbox.
     *
     * @param int $page Current page.
     * @param int $totalPages Total page count.
     * @return array{prev: ?string, next: ?string, pages: array<int, array{0: ?string, 1: int|string, 2: bool}>}
     */
    private static function buildNotificationsPaginationState(int $page, int $totalPages): array
    {
        $buildPageUrl = static function (int $targetPage): string {
            return $targetPage <= 1
                ? '/community/notifications'
                : '/community/notifications/page/' . $targetPage;
        };

        $paginationPrev = $page > 1 ? $buildPageUrl($page - 1) : null;
        $paginationNext = $page < $totalPages ? $buildPageUrl($page + 1) : null;
        $range = 2;
        $startPage = max(1, $page - $range);
        $endPage = min($totalPages, $page + $range);
        $paginationPages = [];

        if ($startPage > 1)
        {
            $paginationPages[] = [$buildPageUrl(1), 1, false];
            if ($startPage > 2)
            {
                $paginationPages[] = [null, '...', false];
            }
        }

        for ($currentPage = $startPage; $currentPage <= $endPage; $currentPage++)
        {
            $paginationPages[] = [
                $buildPageUrl($currentPage),
                $currentPage,
                $currentPage === $page,
            ];
        }

        if ($endPage < $totalPages)
        {
            if ($endPage < ($totalPages - 1))
            {
                $paginationPages[] = [null, '...', false];
            }

            $paginationPages[] = [$buildPageUrl($totalPages), $totalPages, false];
        }

        return [
            'prev' => $paginationPrev,
            'next' => $paginationNext,
            'pages' => $paginationPages,
        ];
    }

    /**
     * Assign the notifications inbox template state.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array<int, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: int, 6: string}> $notificationRows
     * @param array{prev: ?string, next: ?string, pages: array<int, array{0: ?string, 1: int|string, 2: bool}>} $paginationState
     * @param int $totalRows Total notification count.
     * @param int $page Current page.
     * @param int $totalPages Total page count.
     * @return void
     */
    private static function assignNotificationsPage(
        TemplateEngine $template,
        array $notificationRows,
        array $paginationState,
        int $totalRows,
        int $page,
        int $totalPages
    ): void
    {
        $template->assign('notifications_page_title', 'Notifications');
        $template->assign('notifications_page_description', 'Important community notices, rules updates, and future account messages are shown here.');
        $template->assign('notification_rows', $notificationRows);
        $template->assign('pagination_prev', $paginationState['prev']);
        $template->assign('pagination_next', $paginationState['next']);
        $template->assign('pagination_pages', $paginationState['pages']);
        $template->assign('notifications_total', $totalRows);
        $template->assign('notifications_current_page', $page);
        $template->assign('notifications_total_pages', $totalPages);
    }

    /**
     * Render the user notifications page.
     *
     * @param int|null $page
     * @return void
     */
    public static function index(?int $page = null): void
    {
        $userId = self::requireAuthenticatedUserId();
        $perPage = 10;
        $totalRows = NotificationModel::countForUser($userId);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = self::normalizeNotificationsPage($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = NotificationModel::listForUser($userId, $perPage, $offset);
        $notificationRows = self::buildNotificationRows($rows);
        $paginationState = self::buildNotificationsPaginationState($page, $totalPages);

        NotificationModel::markAllReadForUser($userId);

        $template = self::initTemplate();
        self::assignNotificationsPage($template, $notificationRows, $paginationState, $totalRows, $page, $totalPages);
        $template->render('community/notifications_index.html');
    }
}
