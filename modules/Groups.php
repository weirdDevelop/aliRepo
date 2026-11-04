<?php
/**
 * modules/Groups.php
 * مدیریت گروه‌ها و تگ‌ها روی لایه‌ی شخص (نه کانال).
 * هر دو رابطه‌ی N:M با persons دارند.
 */

class Groups
{
    // ---------- گروه‌ها ----------
    public static function allGroups(): array
    {
        return Database::all(
            "SELECT g.*, (SELECT COUNT(*) FROM eb_person_groups WHERE group_id=g.id) AS member_count
             FROM eb_groups g ORDER BY g.created_at DESC"
        );
    }

    public static function createGroup(string $name, string $color = '#2563eb', ?string $desc = null): int
    {
        Database::run(
            "INSERT INTO eb_groups (name, color, description) VALUES (?, ?, ?)",
            [$name, $color, $desc]
        );
        return Database::lastId();
    }

    public static function deleteGroup(int $id): void
    {
        Database::run("DELETE FROM eb_person_groups WHERE group_id = ?", [$id]);
        Database::run("DELETE FROM eb_groups WHERE id = ?", [$id]);
    }

    public static function addPersons(int $groupId, array $personIds): void
    {
        foreach ($personIds as $pid) {
            Database::run(
                "INSERT IGNORE INTO eb_person_groups (group_id, person_id) VALUES (?, ?)",
                [$groupId, (int)$pid]
            );
        }
    }

    public static function removePerson(int $groupId, int $personId): void
    {
        Database::run(
            "DELETE FROM eb_person_groups WHERE group_id = ? AND person_id = ?",
            [$groupId, $personId]
        );
    }

    // ---------- تگ‌ها ----------
    public static function allTags(): array
    {
        return Database::all(
            "SELECT t.*, (SELECT COUNT(*) FROM eb_person_tags WHERE tag_id=t.id) AS person_count
             FROM eb_tags t ORDER BY t.name"
        );
    }

    /** ساخت تگ در صورت نبود، و بازگرداندن id آن */
    public static function ensureTag(string $name): int
    {
        $name = trim($name);
        $row = Database::one("SELECT id FROM eb_tags WHERE name = ?", [$name]);
        if ($row) return (int)$row['id'];
        Database::run("INSERT INTO eb_tags (name) VALUES (?)", [$name]);
        return Database::lastId();
    }

    public static function deleteTag(int $id): void
    {
        Database::run("DELETE FROM eb_person_tags WHERE tag_id = ?", [$id]);
        Database::run("DELETE FROM eb_tags WHERE id = ?", [$id]);
    }

    public static function tagPersons(int $tagId, array $personIds): void
    {
        foreach ($personIds as $pid) {
            Database::run(
                "INSERT IGNORE INTO eb_person_tags (tag_id, person_id) VALUES (?, ?)",
                [$tagId, (int)$pid]
            );
        }
    }

    public static function untagPerson(int $tagId, int $personId): void
    {
        Database::run(
            "DELETE FROM eb_person_tags WHERE tag_id = ? AND person_id = ?",
            [$tagId, $personId]
        );
    }
}
