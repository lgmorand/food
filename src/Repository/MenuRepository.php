<?php

declare(strict_types=1);

namespace Food\Repository;

use Food\Database;
use Food\Domain\MenuGenerator;
use Food\Http\HttpException;
use Food\Support;

final class MenuRepository
{
    public const ALLOWED_SIZES = [5, 6];

    public function __construct(
        private readonly MenuGenerator $generator = new MenuGenerator(),
        private readonly RecipeRepository $recipes = new RecipeRepository(),
    ) {
    }

    public function find(string $householdId, string $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM menus WHERE household_id = ? AND id = ?');
        $stmt->execute([$householdId, $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findOrFail(string $householdId, string $id): array
    {
        return $this->find($householdId, $id) ?? throw HttpException::notFound('Menu introuvable.');
    }

    public function currentDraft(string $householdId, string $weekStart): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM menus WHERE household_id = ? AND week_start = ? AND status = 'draft' LIMIT 1"
        );
        $stmt->execute([$householdId, $weekStart]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function validatedForWeek(string $householdId, string $weekStart): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM menus WHERE household_id = ? AND week_start = ? AND status = 'validated' LIMIT 1"
        );
        $stmt->execute([$householdId, $weekStart]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function history(string $householdId, int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM menus WHERE household_id = ? AND status IN ('validated','archived')
             ORDER BY week_start DESC, validated_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $householdId);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    /** Génère (ou régénère) le brouillon de la semaine. */
    public function generate(string $householdId, int $size, ?string $weekStart = null): array
    {
        if (!in_array($size, self::ALLOWED_SIZES, true)) {
            throw HttpException::badRequest('Le menu doit contenir 5 ou 6 recettes.');
        }
        $weekStart = Support::mondayOf($weekStart);

        return Database::transaction(function () use ($householdId, $size, $weekStart) {
            $pdo = Database::connection();
            $existing = $this->currentDraft($householdId, $weekStart);
            if ($existing !== null) {
                $pdo->prepare('DELETE FROM menus WHERE id = ?')->execute([$existing['id']]);
            }

            $id = Support::uuid();
            $pdo->prepare(
                'INSERT INTO menus (id, household_id, week_start, size, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $householdId, $weekStart, $size, 'draft', Support::now()]);

            $picked = $this->generator->draw($householdId, $size);
            $this->writeItems($id, $picked, []);

            return $this->findOrFail($householdId, $id);
        });
    }

    /** Retire un nouveau tirage pour toutes les cartes non verrouillées. */
    public function regenerate(string $householdId, string $menuId): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);

        return Database::transaction(function () use ($householdId, $menu) {
            $locked = [];
            foreach ($menu['items'] as $item) {
                if ($item['locked'] && $item['recipeId'] !== null) {
                    $locked[$item['position']] = $item['recipeId'];
                }
            }

            $needed = $menu['size'] - count($locked);
            $picked = $this->generator->draw($householdId, $needed, array_values($locked), $menu['id']);

            $final = [];
            $pool = $picked;
            for ($position = 0; $position < $menu['size']; $position++) {
                if (isset($locked[$position])) {
                    $final[$position] = $locked[$position];
                    continue;
                }
                $next = array_shift($pool);
                if ($next !== null) {
                    $final[$position] = $next;
                }
            }

            Database::connection()->prepare('DELETE FROM menu_items WHERE menu_id = ?')->execute([$menu['id']]);
            $this->writeItems($menu['id'], array_values($final), array_keys($locked));

            return $this->findOrFail($householdId, $menu['id']);
        });
    }

    /**
     * Remplace la recette d'une position par un tirage aléatoire parmi les
     * recettes actives absentes du menu.
     */
    public function replaceItem(string $householdId, string $menuId, int $position): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);
        $item = $this->itemAt($menu, $position);

        $current = array_values(array_filter(array_map(
            static fn (array $i) => $i['recipeId'],
            $menu['items']
        )));

        $candidates = $this->generator->draw($householdId, 1, $current, $menu['id']);
        if ($candidates === []) {
            throw HttpException::conflict(
                "Aucune autre recette disponible pour le remplacement : ajoutez des recettes au catalogue."
            );
        }

        $this->setItemRecipe($menu['id'], $position, $candidates[0], (bool) $item['locked']);

        return $this->findOrFail($householdId, $menuId);
    }

    /** Choix manuel d'une recette pour une position donnée. */
    public function assignItem(string $householdId, string $menuId, int $position, string $recipeId): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);
        $item = $this->itemAt($menu, $position);
        $recipe = $this->recipes->findOrFail($householdId, $recipeId);

        foreach ($menu['items'] as $other) {
            if ($other['position'] !== $position && $other['recipeId'] === $recipeId) {
                throw HttpException::conflict("« {$recipe['name']} » est déjà dans le menu de la semaine.");
            }
        }

        $this->setItemRecipe($menu['id'], $position, $recipeId, (bool) $item['locked']);

        return $this->findOrFail($householdId, $menuId);
    }

    /** Verrouille/déverrouille une carte (protégée lors d'un « Tout regénérer »). */
    public function toggleLock(string $householdId, string $menuId, int $position, bool $locked): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);
        $this->itemAt($menu, $position);

        Database::connection()->prepare('UPDATE menu_items SET locked = ? WHERE menu_id = ? AND position = ?')
            ->execute([(int) $locked, $menuId, $position]);

        return $this->findOrFail($householdId, $menuId);
    }

    /**
     * Supprime une carte : par défaut un nouveau tirage la remplace
     * (comportement attendu côté produit). Si aucune recette de remplacement
     * n'est disponible, la carte est retirée et la taille du menu ajustée.
     */
    public function removeItem(string $householdId, string $menuId, int $position): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);
        $this->itemAt($menu, $position);

        $current = array_values(array_filter(array_map(
            static fn (array $i) => $i['recipeId'],
            $menu['items']
        )));
        $candidates = $this->generator->draw($householdId, 1, $current, $menu['id']);

        if ($candidates !== []) {
            $this->setItemRecipe($menu['id'], $position, $candidates[0], false);

            return $this->findOrFail($householdId, $menuId);
        }

        return Database::transaction(function () use ($householdId, $menu, $position) {
            $pdo = Database::connection();
            $pdo->prepare('DELETE FROM menu_items WHERE menu_id = ? AND position = ?')->execute([$menu['id'], $position]);

            $kept = array_values(array_filter(
                $menu['items'],
                static fn (array $i) => $i['position'] !== $position
            ));
            $pdo->prepare('DELETE FROM menu_items WHERE menu_id = ?')->execute([$menu['id']]);
            $this->writeItems(
                $menu['id'],
                array_map(static fn (array $i) => $i['recipeId'], $kept),
                []
            );
            $pdo->prepare('UPDATE menus SET size = ? WHERE id = ?')->execute([count($kept), $menu['id']]);

            return $this->findOrFail($householdId, $menu['id']);
        });
    }

    public function validate(string $householdId, string $menuId): array
    {
        $menu = $this->findOrFail($householdId, $menuId);
        $this->assertDraft($menu);

        $filled = array_values(array_filter($menu['items'], static fn (array $i) => $i['recipeId'] !== null));
        if ($filled === []) {
            throw HttpException::badRequest('Impossible de valider un menu vide.');
        }

        return Database::transaction(function () use ($householdId, $menu) {
            $pdo = Database::connection();
            // Un seul menu validé par semaine : l'ancien est archivé.
            $pdo->prepare(
                "UPDATE menus SET status = 'archived' WHERE household_id = ? AND week_start = ? AND status = 'validated'"
            )->execute([$householdId, $menu['weekStart']]);

            $pdo->prepare("UPDATE menus SET status = 'validated', validated_at = ? WHERE id = ?")
                ->execute([Support::now(), $menu['id']]);

            return $this->findOrFail($householdId, $menu['id']);
        });
    }

    /** Crée un nouveau brouillon à partir d'un menu existant. */
    public function replay(string $householdId, string $menuId, ?string $weekStart = null): array
    {
        $source = $this->findOrFail($householdId, $menuId);
        $weekStart = Support::mondayOf($weekStart);

        $recipeIds = [];
        foreach ($source['items'] as $item) {
            if ($item['recipeId'] !== null) {
                $recipeIds[] = $item['recipeId'];
            }
        }
        if ($recipeIds === []) {
            throw HttpException::badRequest('Ce menu ne contient plus aucune recette existante.');
        }

        return Database::transaction(function () use ($householdId, $weekStart, $recipeIds, $source) {
            $pdo = Database::connection();
            $existing = $this->currentDraft($householdId, $weekStart);
            if ($existing !== null) {
                $pdo->prepare('DELETE FROM menus WHERE id = ?')->execute([$existing['id']]);
            }

            $id = Support::uuid();
            $pdo->prepare(
                'INSERT INTO menus (id, household_id, week_start, size, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $householdId, $weekStart, count($recipeIds), 'draft', Support::now()]);

            $this->writeItems($id, $recipeIds, []);
            unset($source);

            return $this->findOrFail($householdId, $id);
        });
    }

    public function delete(string $householdId, string $menuId): void
    {
        $this->findOrFail($householdId, $menuId);
        Database::connection()->prepare('DELETE FROM menus WHERE id = ? AND household_id = ?')
            ->execute([$menuId, $householdId]);
    }

    private function assertDraft(array $menu): void
    {
        if ($menu['status'] !== 'draft') {
            throw HttpException::conflict('Ce menu est déjà validé : il ne peut plus être modifié.');
        }
    }

    private function itemAt(array $menu, int $position): array
    {
        foreach ($menu['items'] as $item) {
            if ($item['position'] === $position) {
                return $item;
            }
        }

        throw HttpException::notFound('Position inconnue dans ce menu.');
    }

    private function setItemRecipe(string $menuId, int $position, string $recipeId, bool $locked): void
    {
        $stmt = Database::connection()->prepare('SELECT name, photo_url FROM recipes WHERE id = ?');
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch() ?: ['name' => 'Recette supprimée', 'photo_url' => null];

        Database::connection()->prepare(
            'INSERT INTO menu_items (menu_id, position, recipe_id, recipe_name, recipe_photo, locked)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(menu_id, position) DO UPDATE SET
                recipe_id = excluded.recipe_id,
                recipe_name = excluded.recipe_name,
                recipe_photo = excluded.recipe_photo,
                locked = excluded.locked'
        )->execute([$menuId, $position, $recipeId, $recipe['name'], $recipe['photo_url'], (int) $locked]);
    }

    /**
     * @param string[] $recipeIds
     * @param int[] $lockedPositions
     */
    private function writeItems(string $menuId, array $recipeIds, array $lockedPositions): void
    {
        $locked = array_flip($lockedPositions);
        foreach (array_values($recipeIds) as $position => $recipeId) {
            if ($recipeId === null) {
                continue;
            }
            $this->setItemRecipe($menuId, $position, $recipeId, isset($locked[$position]));
        }
    }

    private function hydrate(array $row): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT mi.*, r.photo_url AS current_photo, r.is_active
             FROM menu_items mi LEFT JOIN recipes r ON r.id = mi.recipe_id
             WHERE mi.menu_id = ? ORDER BY mi.position'
        );
        $stmt->execute([$row['id']]);

        $items = array_map(static fn (array $i) => [
            'position' => (int) $i['position'],
            'recipeId' => $i['recipe_id'],
            'recipeName' => $i['recipe_name'],
            'photoUrl' => $i['current_photo'] ?? $i['recipe_photo'],
            'locked' => (bool) $i['locked'],
            'missing' => $i['recipe_id'] === null,
        ], $stmt->fetchAll());

        return [
            'id' => $row['id'],
            'weekStart' => $row['week_start'],
            'size' => (int) $row['size'],
            'status' => $row['status'],
            'validatedAt' => $row['validated_at'],
            'createdAt' => $row['created_at'],
            'items' => $items,
            'recipeIds' => array_values(array_filter(array_map(
                static fn (array $i) => $i['recipeId'],
                $items
            ))),
        ];
    }
}
