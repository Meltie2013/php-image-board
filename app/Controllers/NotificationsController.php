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
     * Render the user notifications page.
     *
     * @param int|null $page
     * @return void
     */
    public static function index(?int $page = null): void
    {
        RoleHelper::requireLogin();

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $page = max(1, TypeHelper::toInt($page) ?? 1);
        $perPage = 10;
        $totalRows = NotificationModel::countForUser($userId);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $rows = NotificationModel::listForUser($userId, $perPage, $offset);
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

        NotificationModel::markAllReadForUser($userId);

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

        $template = self::initTemplate();
        $template->assign('notifications_page_title', 'Notifications');
        $template->assign('notifications_page_description', 'Important community notices, rules updates, and future account messages are shown here.');
        $template->assign('notification_rows', $notificationRows);
        $template->assign('pagination_prev', $paginationPrev);
        $template->assign('pagination_next', $paginationNext);
        $template->assign('pagination_pages', $paginationPages);
        $template->assign('notifications_total', $totalRows);
        $template->assign('notifications_current_page', $page);
        $template->assign('notifications_total_pages', $totalPages);
        $template->render('community/notifications_index.html');
    }
}
