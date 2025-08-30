<?php

class RoleHelper
{
    /**
     * Get the role name by ID.
     *
     * Useful when you only have the role_id stored with a user record
     * and want to retrieve the human-readable role name.
     *
     * @param int $id Role ID from app_roles
     * @return string|null Returns the role name, or null if not found
     */
    public static function getRoleNameById(int $id): ?string
    {
        $role = Database::fetch("SELECT name FROM app_roles WHERE id = :id LIMIT 1", ['id' => $id]);
        return $role['name'] ?? null;
    }

    /**
     * Get the role description by ID.
     *
     * Can be used for displaying more detailed information about a role,
     * for example on an admin settings or user profile page.
     *
     * @param int $id Role ID from app_roles
     * @return string|null Returns the role description, or null if not found
     */
    public static function getRoleDescriptionById(int $id): ?string
    {
        $role = Database::fetch("SELECT description FROM app_roles WHERE id = :id LIMIT 1", ['id' => $id]);
        return $role['description'] ?? null;
    }

    /**
     * Get the role name by its name (validation check).
     *
     * Useful for verifying that a role actually exists in the database
     * before assigning it to a user.
     *
     * @param string $name Exact role name from app_roles
     * @return string|null Returns the role name, or null if not found
     */
    public static function getRoleNameByName(string $name): ?string
    {
        $role = Database::fetch("SELECT name FROM app_roles WHERE name = :name LIMIT 1", ['name' => $name]);
        return $role['name'] ?? null;
    }

    /**
     * Get the role description by role name.
     *
     * Allows retrieving human-readable descriptions of roles for display
     * in UIs, logs, or audit records.
     *
     * @param string $name Exact role name from app_roles
     * @return string|null Returns the role description, or null if not found
     */
    public static function getRoleDescriptionByName(string $name): ?string
    {
        $role = Database::fetch("SELECT description FROM app_roles WHERE name = :name LIMIT 1", ['name' => $name]);
        return $role['description'] ?? null;
    }

    /**
     * Require that the current user is logged in.
     *
     * If the user is not authenticated, they will be redirected to the
     * login page. Use this at the beginning of any protected controller
     * action where authentication is mandatory.
     *
     * @return void
     */
    public static function requireLogin(): void
    {
        $userId = SessionManager::get('user_id');
        if (!$userId)
        {
            header('Location: /user/login');
            exit();
        }
    }

    /**
     * Require that the current user has one of the given roles.
     *
     * This helper ensures that only users with specific roles can access
     * certain areas of the application. If the user does not match an
     * allowed role, they will be shown an access denied page.
     *
     * @param array $allowedRoles Array of allowed role names (e.g. ['administrator','moderator'])
     * @param TemplateEngine|null $template Optional template engine for rendering a nicer error page
     * @return void
     */
    public static function requireRole(array $allowedRoles, ?TemplateEngine $template = null): void
    {
        $userId = SessionManager::get('user_id');
        $userRole = strtolower(SessionManager::get('user_role') ?? '');

        if (!$userId || !in_array($userRole, array_map('strtolower', $allowedRoles)))
        {
            http_response_code(403);

            if ($template)
            {
                $template->assign('title', 'Access Denied');
                $template->assign('message', 'You are not authorized to view this page.');
                $template->render('errors/error_page.html');
            }
            exit();
        }
    }
}
