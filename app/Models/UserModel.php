<?php

/**
 * User-related database access helpers.
 */
class UserModel extends BaseModel
{
    /**
     * Fetch one username by user id.
     *
     * @param int|null $userId
     * @return string
     */
    public static function getUsernameById(?int $userId): string
    {
        if ($userId === null || $userId < 1)
        {
            return '';
        }

        $result = self::fetch("SELECT username FROM app_users WHERE id = :id LIMIT 1", [':id' => $userId]);
        return TypeHelper::toString($result['username'] ?? '') ?? '';
    }

    /**
     * Fetch minimal age-verification data for one user.
     *
     * @param int $userId
     * @return array|null
     */
    public static function findAgeVerificationById(int $userId): ?array
    {
        if ($userId < 1)
        {
            return null;
        }

        return self::fetch(
            "SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
            [':id' => $userId]
        );
    }

    /**
     * Fetch one login record by email.
     *
     * @param string $email
     * @return array|null
     */
    public static function findLoginUserByEmail(string $email): ?array
    {
        if ($email === '')
        {
            return null;
        }

        return self::fetch(
            "SELECT u.id, u.username, u.email, u.password_hash, u.avatar_path, u.failed_logins, u.last_failed_login, u.status, r.name AS group_name
             FROM app_users u
             INNER JOIN app_groups r ON u.group_id = r.id
             WHERE u.email = :email
             LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * Suspend one account.
     *
     * @param int $userId
     * @return void
     */
    public static function suspendById(int $userId): void
    {
        if ($userId < 1)
        {
            return;
        }

        self::query("UPDATE app_users SET status = 'suspended' WHERE id = :id", ['id' => $userId]);
    }

    /**
     * Reset failed login counters on successful authentication.
     *
     * @param int $userId
     * @return void
     */
    public static function resetLoginState(int $userId): void
    {
        if ($userId < 1)
        {
            return;
        }

        self::query(
            "UPDATE app_users
             SET failed_logins = 0,
                 last_failed_login = NULL,
                 last_login = NOW()
             WHERE id = :id",
            ['id' => $userId]
        );
    }

    /**
     * Store failed login counters for one account.
     *
     * @param int $userId
     * @param int $failedLogins
     * @return void
     */
    public static function updateFailedLoginState(int $userId, int $failedLogins): void
    {
        if ($userId < 1)
        {
            return;
        }

        self::query(
            "UPDATE app_users
             SET failed_logins = :fails,
                 last_failed_login = NOW()
             WHERE id = :id",
            ['fails' => $failedLogins, 'id' => $userId]
        );
    }

    /**
     * Check whether one username or email is already in use.
     *
     * @param string $username
     * @param string $email
     * @return bool
     */
    public static function usernameOrEmailExists(string $username, string $email): bool
    {
        $exists = self::fetch(
            "SELECT id
             FROM app_users
             WHERE username = :username OR email = :email
             LIMIT 1",
            ['username' => $username, 'email' => $email]
        );

        return TypeHelper::rowExists($exists);
    }

    /**
     * Insert one new application user.
     *
     * @param string $username
     * @param string $email
     * @param string $passwordHash
     * @param int $groupId
     * @param string $status
     * @return int
     */
    public static function createPendingUser(string $username, string $email, string $passwordHash, int $groupId = 6, string $status = 'pending'): int
    {
        return (int) self::insert(
            "INSERT INTO app_users (username, email, password_hash, group_id, status, created_at)
             VALUES (:username, :email, :password_hash, :group_id, :status, NOW())",
            [
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'group_id' => $groupId,
                'status' => $status,
            ]
        );
    }

    /**
     * Fetch one user profile row with overview counters.
     *
     * @param int $userId
     * @return array|null
     */
    public static function findProfileOverviewById(int $userId): ?array
    {
        if ($userId < 1)
        {
            return null;
        }

        return self::fetch(
            "SELECT
                u.id,
                u.group_id,
                u.username,
                u.display_name,
                u.email,
                u.avatar_path,
                u.date_of_birth,
                u.age_verified_at,
                u.status,
                u.last_login,
                u.created_at,
                COALESCE(f.favorite_count, 0) AS favorite_count,
                COALESCE(v.vote_count, 0) AS vote_count
             FROM app_users u
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS favorite_count
                  FROM app_image_favorites
                 GROUP BY user_id
             ) f ON u.id = f.user_id
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS vote_count
                  FROM app_image_votes
                 GROUP BY user_id
             ) v ON u.id = v.user_id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $userId]
        );
    }

    /**
     * Count approved uploads owned by one user.
     *
     * @param int $userId
     * @return int
     */
    public static function countApprovedImagesByUserId(int $userId): int
    {
        if ($userId < 1)
        {
            return 0;
        }

        $row = self::fetch(
            "SELECT COUNT(*) AS count
             FROM app_images
             WHERE user_id = :id AND status = 'approved'",
            ['id' => $userId]
        );

        return TypeHelper::toInt($row['count'] ?? 0) ?? 0;
    }

    /**
     * Fetch one profile-management row.
     *
     * @param int $userId
     * @return array|null
     */
    public static function findProfileSettingsUserById(int $userId): ?array
    {
        if ($userId < 1)
        {
            return null;
        }

        return self::fetch(
            "SELECT
                id,
                group_id,
                username,
                display_name,
                email,
                avatar_path,
                date_of_birth,
                status,
                last_login,
                created_at,
                password_hash,
                age_verified_at
             FROM app_users
             WHERE id = :id
             LIMIT 1",
            ['id' => $userId]
        );
    }

    /**
     * Update one email address.
     *
     * @param int $userId
     * @param string $email
     * @return void
     */
    public static function updateEmail(int $userId, string $email): void
    {
        self::query("UPDATE app_users SET email = :email WHERE id = :id", ['email' => $email, 'id' => $userId]);
    }

    /**
     * Update one date of birth and mark the account as age verified.
     *
     * @param int $userId
     * @param string $dob
     * @return void
     */
    public static function updateDobAndVerify(int $userId, string $dob): void
    {
        self::query(
            "UPDATE app_users
             SET date_of_birth = :dob,
                 age_verified_at = NOW()
             WHERE id = :id",
            ['dob' => $dob, 'id' => $userId]
        );
    }

    /**
     * Update one avatar path.
     *
     * @param int $userId
     * @param string $path
     * @return void
     */
    public static function updateAvatarPath(int $userId, string $path): void
    {
        self::query("UPDATE app_users SET avatar_path = :path WHERE id = :id", ['path' => $path, 'id' => $userId]);
    }

    /**
     * Update one password hash.
     *
     * @param int $userId
     * @param string $hash
     * @return void
     */
    public static function updatePasswordHash(int $userId, string $hash): void
    {
        self::query("UPDATE app_users SET password_hash = :hash WHERE id = :id", ['hash' => $hash, 'id' => $userId]);
    }

    /**
     * Fetch one role id by user id.
     *
     * @param int $userId
     * @return int
     */
    public static function getGroupIdByUserId(int $userId): int
    {
        $row = self::fetch("SELECT group_id FROM app_users WHERE id = :id LIMIT 1", [':id' => $userId]);
        return TypeHelper::toInt($row['group_id'] ?? 0) ?? 0;
    }

    
    /**
     * Backwards-compatible wrapper for legacy role-based callers.
     *
     * @param int $userId
     * @return int
     */
    public static function getRoleIdByUserId(int $userId): int
    {
        return self::getGroupIdByUserId($userId);
    }

    /**
     * Count non-deleted users for the dashboard.
     *
     * @return int
     */
    public static function countNonDeletedUsers(): int
    {
        $row = self::fetch("SELECT COUNT(*) AS total FROM app_users WHERE status != 'deleted'") ?? [];
        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }

    /**
     * Return the ACP user list.
     *
     * @return array
     */
    public static function listPanelUsers(): array
    {
        return self::fetchAll(
            "SELECT u.id, u.username, u.display_name, u.email, u.status, u.created_at, r.name AS group_name
             FROM app_users u
             INNER JOIN app_groups r ON u.group_id = r.id
             WHERE u.status != 'deleted'
             ORDER BY u.id ASC"
        );
    }

    /**
     * Return available role rows.
     *
     * @return array
     */
    public static function listGroups(): array
    {
        return self::fetchAll("SELECT id, name FROM app_groups ORDER BY id ASC");
    }

    
    /**
     * Backwards-compatible wrapper for legacy role list callers.
     *
     * @return array
     */
    public static function listRoles(): array
    {
        return self::listGroups();
    }

    /**
     * Insert one ACP-managed user row.
     *
     * @param int $groupId
     * @param string $username
     * @param string|null $displayName
     * @param string $email
     * @param string $passwordHash
     * @param string $status
     * @return void
     */
    public static function createPanelUser(int $groupId, string $username, ?string $displayName, string $email, string $passwordHash, string $status): void
    {
        self::query(
            "INSERT INTO app_users (group_id, username, display_name, email, password_hash, status, created_at, updated_at)
             VALUES (:group_id, :username, :display, :email, :hash, :status, NOW(), NOW())",
            [
                'group_id' => $groupId,
                'username' => $username,
                'display' => $displayName,
                'email' => $email,
                'hash' => $passwordHash,
                'status' => $status,
            ]
        );
    }

    /**
     * Fetch one ACP-editable user row.
     *
     * @param int $id
     * @return array|null
     */
    public static function findPanelUserById(int $id): ?array
    {
        if ($id < 1)
        {
            return null;
        }

        return self::fetch(
            "SELECT u.id, u.group_id, u.username, u.display_name, u.email, u.status, r.name AS group_name
             FROM app_users u
             INNER JOIN app_groups r ON u.group_id = r.id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Update one ACP-managed user row.
     *
     * @param int $id
     * @param int $groupId
     * @param string $username
     * @param string|null $displayName
     * @param string $email
     * @param string $status
     * @return void
     */
    public static function updatePanelUser(int $id, int $groupId, string $username, ?string $displayName, string $email, string $status): void
    {
        self::query(
            "UPDATE app_users
             SET group_id = :group_id,
                 username = :username,
                 display_name = :display,
                 email = :email,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id",
            [
                'group_id' => $groupId,
                'username' => $username,
                'display' => $displayName,
                'email' => $email,
                'status' => $status,
                'id' => $id,
            ]
        );
    }
}
