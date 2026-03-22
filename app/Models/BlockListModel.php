<?php

/**
 * Block list data access helpers.
 */
class BlockListModel extends BaseModel
{
    /**
     * Count active enforcement rows.
     *
     * @return int
     */
    public static function countActive(): int
    {
        $row = self::fetch("SELECT COUNT(*) AS total FROM app_block_list WHERE expires_at IS NULL OR expires_at > NOW()") ?? [];
        return TypeHelper::toInt($row['total'] ?? 0) ?? 0;
    }

    /**
     * Fetch block rows by filter clause.
     *
     * @param string $sqlWhere
     * @param array $params
     * @return array
     */
    public static function listFiltered(string $sqlWhere, array $params = []): array
    {
        return self::fetchAll(
            "SELECT id, scope, status, reason, user_id, ip, ua, fingerprint, device_fingerprint, browser_fingerprint, created_at, last_seen, expires_at
             FROM app_block_list
             {$sqlWhere}
             ORDER BY id DESC
             LIMIT 500",
            $params
        );
    }

    /**
     * Upsert one block list row.
     *
     * @param array $payload
     * @return void
     */
    public static function upsert(array $payload): void
    {
        self::query(
            "INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, device_fingerprint, browser_fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES (:scope, :vh, :uid, :ip, :ua, :fp, :dfp, :bfp, :status, :reason, NOW(), NOW(), :exp)
             ON DUPLICATE KEY UPDATE status = :status_upd, reason = :reason_upd, last_seen = NOW(), expires_at = :exp_upd",
            $payload
        );
    }

    /**
     * Fetch one block row.
     *
     * @param int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        return self::fetch(
            "SELECT id, scope, status, reason, user_id, ip, ua, fingerprint, device_fingerprint, browser_fingerprint, created_at, last_seen, expires_at
             FROM app_block_list
             WHERE id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Update one block row.
     *
     * @param int $id
     * @param string $status
     * @param string|null $reason
     * @param string|null $expires
     * @return void
     */
    public static function updateById(int $id, string $status, ?string $reason, ?string $expires): void
    {
        self::query(
            "UPDATE app_block_list
             SET status = :status,
                 reason = :reason,
                 expires_at = :exp,
                 last_seen = NOW()
             WHERE id = :id",
            [
                'status' => $status,
                'reason' => $reason,
                'exp' => $expires,
                'id' => $id,
            ]
        );
    }

    /**
     * Delete rows matching one prepared where clause.
     *
     * @param string $whereSql
     * @param array $params
     * @return void
     */
    public static function deleteWhere(string $whereSql, array $params = []): void
    {
        self::query('DELETE FROM app_block_list WHERE ' . $whereSql, $params);
    }

    /**
     * Delete one block row by id.
     *
     * @param int $id
     * @return void
     */
    public static function deleteById(int $id): void
    {
        self::query("DELETE FROM app_block_list WHERE id = :id", ['id' => $id]);
    }
}
