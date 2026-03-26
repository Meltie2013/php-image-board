<?php

/**
 * RulesController
 *
 * Handles the public rules page and user acceptance workflow.
 */
class RulesController extends BaseController
{
    /**
     * Shared template variables for the rules pages.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
    ];

    /**
     * Rules pages render state-changing forms.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Convert a one-based position into an alphabetic marker.
     *
     * Examples: 1 => a, 2 => b, 27 => aa.
     *
     * @param int $position
     * @return string
     */
    protected static function buildCategoryMarker(int $position): string
    {
        $position = max(1, $position);
        $marker = '';

        while ($position > 0)
        {
            $position--;
            $marker = chr(97 + ($position % 26)) . $marker;
            $position = intdiv($position, 26);
        }

        return $marker;
    }

    /**
     * Build one normalized public rules row list.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array{0: int, 1: string, 2: string, 3: string, 4: int}>
     */
    private static function buildPublicRuleRows(array $rules): array
    {
        $ruleRows = [];
        $rulePosition = 0;

        foreach ($rules as $rule)
        {
            $rulePosition++;
            $body = TypeHelper::toString($rule['body'] ?? '', allowEmpty: true) ?? '';
            $ruleRows[] = [
                TypeHelper::toInt($rule['id'] ?? 0) ?? 0,
                TypeHelper::toString($rule['title'] ?? ''),
                TypeHelper::toString($rule['slug'] ?? ''),
                nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                $rulePosition,
            ];
        }

        return $ruleRows;
    }

    /**
     * Build the normalized public rules category rows for the template.
     *
     * @return array<int, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: array<int, array{0: int, 1: string, 2: string, 3: string, 4: int}>}>
     */
    private static function buildPublicRulesCategoryRows(): array
    {
        $categoryRows = [];
        $categoryPosition = 0;

        foreach (RulesModel::listPublicCategoriesWithRules() as $category)
        {
            $categoryPosition++;
            $categoryRows[] = [
                TypeHelper::toInt($category['id'] ?? 0) ?? 0,
                TypeHelper::toString($category['title'] ?? ''),
                TypeHelper::toString($category['slug'] ?? ''),
                TypeHelper::toString($category['description'] ?? ''),
                self::buildCategoryMarker($categoryPosition),
                self::buildPublicRuleRows($category['rules'] ?? []),
            ];
        }

        return $categoryRows;
    }

    /**
     * Assign the public rules page template state.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array<int, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: array<int, array{0: int, 1: string, 2: string, 3: string, 4: int}>}> $categoryRows
     * @param array<string, mixed> $state Current rules release state.
     * @param int $userId Current authenticated user id, or 0.
     * @return void
     */
    private static function assignRulesPage(TemplateEngine $template, array $categoryRows, array $state, int $userId): void
    {
        $template->assign('rules_page_title', 'Community Rules');
        $template->assign('rules_page_description', 'Review the latest community rules and the expectations for account use, uploads, and member conduct across the site.');
        $template->assign('rules_categories', $categoryRows);
        $template->assign('rules_has_release', !empty($state['has_release']) ? 1 : 0);
        $template->assign('rules_release_label', TypeHelper::toString($state['release_label'] ?? ''));
        $template->assign('rules_release_summary', TypeHelper::toString($state['release_summary'] ?? ''));
        $template->assign('rules_release_published_display', TypeHelper::toString($state['release_published_display'] ?? ''));
        $template->assign('rules_is_logged_in', $userId > 0 ? 1 : 0);
        $template->assign('rules_is_accepted', !empty($state['accepted']) ? 1 : 0);
        $template->assign('rules_has_pending', !empty($state['has_pending']) ? 1 : 0);
        $template->assign('rules_is_blocking', !empty($state['is_blocking']) ? 1 : 0);
        $template->assign('rules_deadline_display', TypeHelper::toString($state['deadline_display'] ?? ''));
        $template->assign('rules_notice_title', TypeHelper::toString($state['notice_title'] ?? ''));
        $template->assign('rules_notice_message', TypeHelper::toString($state['notice_message'] ?? ''));
        $template->assign('rules_accept_return_to', RedirectHelper::getRememberedLoginDestination());
    }

    /**
     * Validate the public rules acceptance request.
     *
     * @return bool
     */
    private static function validateRulesAcceptRequest(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            return false;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        return Security::verifyCsrfToken($csrfToken);
    }

    /**
     * Render the public rules page.
     *
     * @return void
     */
    public static function index(): void
    {
        $userId = self::getCurrentUserId();
        $state = RulesHelper::getCurrentStateForUser($userId);
        $categoryRows = self::buildPublicRulesCategoryRows();

        $template = self::initTemplate();
        self::assignRulesPage($template, $categoryRows, $state, $userId);
        $template->render('rules/rules_index.html');
    }

    /**
     * Accept the current rules release for the authenticated user.
     *
     * @return void
     */
    public static function accept(): void
    {
        $userId = self::requireAuthenticatedUserId();

        if (!self::validateRulesAcceptRequest())
        {
            self::renderInvalidRequest();
            return;
        }

        if (!RulesModel::acceptCurrentReleaseForUser($userId))
        {
            self::renderErrorPage(409, 'Unable To Accept Rules', 'The latest rules release could not be accepted. Please try again.');
            return;
        }

        header('Location: ' . RedirectHelper::takeLoginDestination(RedirectHelper::sanitizeInternalPath($_POST['return_to'] ?? null), '/profile/overview'));
        exit();
    }
}
