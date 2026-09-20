<?php

declare(strict_types=1);

namespace Food\Domain;

use Food\Database;

/**
 * Règles de tirage du menu hebdomadaire (cf. SPECS.md §6).
 *
 * - tirage parmi les recettes actives uniquement ;
 * - unicité stricte au sein d'un même menu ;
 * - anti-répétition : les recettes des 2 dernières semaines validées sont
 *   dépriorisées et ne sont tirées qu'en dernier recours.
 */
final class MenuGenerator
{
    /** Nombre de semaines validées prises en compte pour l'anti-répétition. */
    public const RECENT_WEEKS = 2;

    /**
     * @param string[] $excludedRecipeIds recettes déjà présentes dans le menu
     * @return string[] identifiants tirés (peut contenir moins de $count éléments)
     */
    public function draw(string $householdId, int $count, array $excludedRecipeIds = [], ?string $ignoreMenuId = null): array
    {
        if ($count <= 0) {
            return [];
        }

        $pool = $this->activeRecipeIds($householdId);
        $excluded = array_flip($excludedRecipeIds);
        $pool = array_values(array_filter($pool, static fn (string $id) => !isset($excluded[$id])));

        if ($pool === []) {
            return [];
        }

        $recent = $this->recentlyUsedRecipeIds($householdId, $ignoreMenuId);
        $fresh = array_values(array_filter($pool, static fn (string $id) => !isset($recent[$id])));
        $stale = array_values(array_filter($pool, static fn (string $id) => isset($recent[$id])));

        shuffle($fresh);
        // Les recettes récemment utilisées passent en dernier, les plus anciennes d'abord.
        usort($stale, static fn (string $a, string $b) => ($recent[$b] ?? 0) <=> ($recent[$a] ?? 0));

        return array_slice(array_merge($fresh, $stale), 0, $count);
    }

    /** @return string[] */
    public function activeRecipeIds(string $householdId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM recipes WHERE household_id = ? AND is_active = 1'
        );
        $stmt->execute([$householdId]);

        return array_map(static fn (array $row) => $row['id'], $stmt->fetchAll());
    }

    /**
     * Recettes utilisées dans les N dernières semaines validées.
     *
     * @return array<string,string> recipeId => week_start
     */
    public function recentlyUsedRecipeIds(string $householdId, ?string $ignoreMenuId = null): array
    {
        $sql = "SELECT id, week_start FROM menus
                WHERE household_id = ? AND status IN ('validated', 'archived')";
        $params = [$householdId];
        if ($ignoreMenuId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $ignoreMenuId;
        }
        $sql .= ' ORDER BY week_start DESC LIMIT ' . self::RECENT_WEEKS;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $menus = $stmt->fetchAll();
        if ($menus === []) {
            return [];
        }

        $ids = array_column($menus, 'id');
        $weekByMenu = array_column($menus, 'week_start', 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $itemsStmt = Database::connection()->prepare(
            "SELECT menu_id, recipe_id FROM menu_items WHERE menu_id IN ({$placeholders}) AND recipe_id IS NOT NULL"
        );
        $itemsStmt->execute($ids);

        $recent = [];
        foreach ($itemsStmt->fetchAll() as $row) {
            $week = $weekByMenu[$row['menu_id']] ?? '';
            if (!isset($recent[$row['recipe_id']]) || $week > $recent[$row['recipe_id']]) {
                $recent[$row['recipe_id']] = $week;
            }
        }

        return $recent;
    }
}
