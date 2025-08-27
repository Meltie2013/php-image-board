<?php

class RoleHelper
{
    /**
     * Get the role name by ID
     *
     * @param int $id
     * @return string|null
     */
    public static function getRoleNameById(int $id): ?string
    {
        $role = Database::fetch("SELECT name FROM app_roles WHERE id = :id LIMIT 1", ['id' => $id]);
        return $role['name'] ?? null;
    }

    /**
     * Get the role description by ID
     *
     * @param int $id
     * @return string|null
     */
    public static function getRoleDescriptionById(int $id): ?string
    {
        $role = Database::fetch("SELECT description FROM app_roles WHERE id = :id LIMIT 1", ['id' => $id]);
        return $role['description'] ?? null;
    }

    /**
     * Get the role name by role name (useful for validation)
     *
     * @param string $name
     * @return string|null
     */
    public static function getRoleNameByName(string $name): ?string
    {
        $role = Database::fetch("SELECT name FROM app_roles WHERE name = :name LIMIT 1", ['name' => $name]);
        return $role['name'] ?? null;
    }

    /**
     * Get the role description by role name
     *
     * @param string $name
     * @return string|null
     */
    public static function getRoleDescriptionByName(string $name): ?string
    {
        $role = Database::fetch("SELECT description FROM app_roles WHERE name = :name LIMIT 1", ['name' => $name]);
        return $role['description'] ?? null;
    }
}
