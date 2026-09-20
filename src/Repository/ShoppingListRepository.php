<?php

declare(strict_types=1);

namespace Food\Repository;

use Food\Database;
use Food\Domain\ShoppingListBuilder;
use Food\Domain\Units;
use Food\Http\HttpException;
use Food\Support;

final class ShoppingListRepository
{
    public function __construct(
        private readonly ShoppingListBuilder $builder = new ShoppingListBuilder(),
        private readonly MenuRepository $menus = new MenuRepository(),
    ) {
    }

    public function findByMenu(string $householdId, string $menuId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM shopping_lists WHERE household_id = ? AND menu_id = ?'
        );
        $stmt->execute([$householdId, $menuId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function find(string $householdId, string $listId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM shopping_lists WHERE household_id = ? AND id = ?');
        $stmt->execute([$householdId, $listId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw HttpException::notFound('Liste de courses introuvable.');
        }

        return $this->hydrate($row);
    }

    public function current(string $householdId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT sl.* FROM shopping_lists sl
             JOIN menus m ON m.id = sl.menu_id
             WHERE sl.household_id = ? AND m.status = 'validated'
             ORDER BY m.week_start DESC, m.validated_at DESC LIMIT 1"
        );
        $stmt->execute([$householdId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** Génère la liste de courses d'un menu validé (idempotent). */
    public function generateForMenu(string $householdId, string $menuId): array
    {
        $menu = $this->menus->findOrFail($householdId, $menuId);
        if ($menu['status'] === 'draft') {
            throw HttpException::conflict("Le menu doit être validé avant de générer la liste de courses.");
        }

        $existing = $this->findByMenu($householdId, $menuId);
        if ($existing !== null) {
            return $existing;
        }

        return Database::transaction(function () use ($householdId, $menu) {
            $pdo = Database::connection();
            $listId = Support::uuid();
            $pdo->prepare('INSERT INTO shopping_lists (id, menu_id, household_id, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$listId, $menu['id'], $householdId, Support::now()]);

            $insert = $pdo->prepare(
                'INSERT INTO shopping_items (id, list_id, ingredient_id, label, category, quantities, source_recipes, checked, is_manual, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
            );

            foreach ($this->builder->build($menu['items']) as $position => $item) {
                $insert->execute([
                    Support::uuid(),
                    $listId,
                    $item['ingredientId'],
                    $item['label'],
                    $item['category'],
                    json_encode($item['quantities'], JSON_UNESCAPED_UNICODE),
                    json_encode($item['sourceRecipes'], JSON_UNESCAPED_UNICODE),
                    $position,
                ]);
            }

            return $this->find($householdId, $listId);
        });
    }

    public function toggleItem(string $householdId, string $listId, string $itemId, bool $checked): array
    {
        $this->find($householdId, $listId);
        $stmt = Database::connection()->prepare('UPDATE shopping_items SET checked = ? WHERE id = ? AND list_id = ?');
        $stmt->execute([(int) $checked, $itemId, $listId]);
        if ($stmt->rowCount() === 0) {
            throw HttpException::notFound('Article introuvable.');
        }

        return $this->find($householdId, $listId);
    }

    public function uncheckAll(string $householdId, string $listId): array
    {
        $this->find($householdId, $listId);
        Database::connection()->prepare('UPDATE shopping_items SET checked = 0 WHERE list_id = ?')->execute([$listId]);

        return $this->find($householdId, $listId);
    }

    public function addManualItem(string $householdId, string $listId, string $label, ?string $category = null): array
    {
        $this->find($householdId, $listId);
        $label = trim($label);
        if ($label === '') {
            throw HttpException::badRequest("Le libellé de l'article est obligatoire.");
        }

        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM shopping_items WHERE list_id = ?');
        $stmt->execute([$listId]);

        Database::connection()->prepare(
            'INSERT INTO shopping_items (id, list_id, ingredient_id, label, category, quantities, source_recipes, checked, is_manual, position)
             VALUES (?, ?, NULL, ?, ?, ?, ?, 0, 1, ?)'
        )->execute([
            Support::uuid(),
            $listId,
            $label,
            Units::normalizeCategory($category),
            '[]',
            '[]',
            (int) $stmt->fetchColumn(),
        ]);

        return $this->find($householdId, $listId);
    }

    public function deleteItem(string $householdId, string $listId, string $itemId): array
    {
        $this->find($householdId, $listId);
        $stmt = Database::connection()->prepare('DELETE FROM shopping_items WHERE id = ? AND list_id = ?');
        $stmt->execute([$itemId, $listId]);
        if ($stmt->rowCount() === 0) {
            throw HttpException::notFound('Article introuvable.');
        }

        return $this->find($householdId, $listId);
    }

    /** Export texte, pratique pour partager la liste. */
    public function toText(array $list): string
    {
        $lines = ['Liste de courses — semaine du ' . $list['weekStart']];
        foreach ($list['groups'] as $group) {
            $lines[] = '';
            $lines[] = strtoupper($group['label']);
            foreach ($group['items'] as $item) {
                $quantity = $item['display'] !== '' ? ' — ' . $item['display'] : '';
                $lines[] = ($item['checked'] ? '[x] ' : '[ ] ') . $item['label'] . $quantity;
            }
        }

        return implode("\n", $lines);
    }

    private function hydrate(array $row): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM shopping_items WHERE list_id = ? ORDER BY checked, position'
        );
        $stmt->execute([$row['id']]);

        $menuStmt = Database::connection()->prepare('SELECT week_start, status FROM menus WHERE id = ?');
        $menuStmt->execute([$row['menu_id']]);
        $menu = $menuStmt->fetch() ?: ['week_start' => '', 'status' => 'archived'];

        $items = [];
        foreach ($stmt->fetchAll() as $item) {
            $quantities = Support::jsonDecodeList($item['quantities']);
            $items[] = [
                'id' => $item['id'],
                'ingredientId' => $item['ingredient_id'],
                'label' => $item['label'],
                'category' => $item['category'],
                'categoryLabel' => Units::CATEGORIES[$item['category']] ?? 'Autre',
                'quantities' => $quantities,
                'display' => implode(' + ', array_map(
                    static fn (array $q) => $q['display'] ?? '',
                    $quantities
                )),
                'sourceRecipes' => Support::jsonDecodeList($item['source_recipes']),
                'checked' => (bool) $item['checked'],
                'isManual' => (bool) $item['is_manual'],
            ];
        }

        $groups = [];
        foreach (Units::CATEGORY_ORDER as $category) {
            $groupItems = array_values(array_filter($items, static fn (array $i) => $i['category'] === $category));
            if ($groupItems === []) {
                continue;
            }
            usort($groupItems, static fn (array $a, array $b) => [$a['checked'], mb_strtolower($a['label'])]
                <=> [$b['checked'], mb_strtolower($b['label'])]);
            $groups[] = [
                'category' => $category,
                'label' => Units::CATEGORIES[$category],
                'items' => $groupItems,
            ];
        }

        return [
            'id' => $row['id'],
            'menuId' => $row['menu_id'],
            'weekStart' => $menu['week_start'],
            'menuStatus' => $menu['status'],
            'createdAt' => $row['created_at'],
            'groups' => $groups,
            'totalCount' => count($items),
            'checkedCount' => count(array_filter($items, static fn (array $i) => $i['checked'])),
        ];
    }
}
