<?php

/**
 * Security log data access helpers.
 */
class SecurityLogModel extends BaseModel
{
    /**
     * Fetch the newest security log rows.
     *
     * @param int $limit
     * @return array
     */
    public static function listRecent(int $limit = 5): array
    {
        $stmt = self::getPDO()->prepare(
            "SELECT id, user_id, category, message, created_at
             FROM app_security_logs
             ORDER BY id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count matching security logs.
     *
     * @param string $sqlWhere
     * @param array $params
     * @return int
     */
    public static function countMatching(string $sqlWhere, array $params = []): int
    {
        $row = self::fetch(
            "SELECT COUNT(*) AS cnt
             FROM app_security_logs l
             {$sqlWhere}",
            $params
        );

        return TypeHelper::toInt($row['cnt'] ?? 0) ?? 0;
    }

    /**
     * Fetch one page of filtered security logs.
     *
     * @param string $sqlWhere
     * @param array $params
     * @param int $perPage
     * @param int $offset
     * @return array
     */
    public static function fetchPage(string $sqlWhere, array $params, int $perPage, int $offset): array
    {
        $sql = "SELECT l.id, l.user_id, l.session_id, l.ip, l.fingerprint, l.device_fingerprint, l.browser_fingerprint, l.category, l.created_at
                FROM app_security_logs l
                {$sqlWhere}
                ORDER BY l.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = self::getPDO()->prepare($sql);
        foreach ($params as $key => $value)
        {
            $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch distinct category values.
     *
     * @return array
     */
    public static function listCategories(): array
    {
        return self::fetchAll("SELECT DISTINCT category FROM app_security_logs ORDER BY category ASC LIMIT 100");
    }

    /**
     * Fetch one log row by id.
     *
     * @param int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        return self::fetch(
            "SELECT l.id, l.user_id, l.session_id, l.ip, l.ua, l.fingerprint, l.device_fingerprint, l.browser_fingerprint, l.category, l.message, l.created_at
             FROM app_security_logs l
             WHERE l.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Resolve users linked by device/browser fingerprints.
     *
     * @param string $deviceFingerprint
     * @param string $browserFingerprint
     * @return array
     */
    public static function findLinkedUsers(string $deviceFingerprint, string $browserFingerprint): array
    {
        if ($deviceFingerprint === '' && $browserFingerprint === '')
        {
            return [];
        }

        $where = [];
        $params = [];

        if ($deviceFingerprint !== '')
        {
            $where[] = 'd.device_fingerprint = :dfp';
            $params['dfp'] = $deviceFingerprint;
        }

        if ($browserFingerprint !== '')
        {
            $where[] = 'd.browser_fingerprint = :bfp';
            $params['bfp'] = $browserFingerprint;
        }

        return self::fetchAll(
            "SELECT DISTINCT u.id, u.username
             FROM app_user_devices d
             INNER JOIN app_users u ON u.id = d.user_id
             WHERE " . implode(' OR ', $where) . "
             ORDER BY u.username ASC
             LIMIT 25",
            $params
        );
    }
}
