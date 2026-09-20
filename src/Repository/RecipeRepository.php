<?php

declare(strict_types=1);

namespace Food\Repository;

use Food\Database;
use Food\Domain\Units;
use Food\Http\HttpException;
use Food\Support;

final class RecipeRepository
{
    public function __construct(
        private readonly IngredientRepository $ingredients = new IngredientRepository(),
    ) {
    }

    /**
     * @param array{search?:string, tag?:string, onlyActive?:bool} $filters
     */
    public function all(string $householdId, array $filters = []): array
    {
        $sql = 'SELECT * FROM recipes WHERE household_id = ?';
        $params = [$householdId];

        if (!empty($filters['onlyActive'])) {
            $sql .= ' AND is_active = 1';
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND name_key LIKE ?';
            $params[] = '%' . Support::normalizeKey((string) $filters['search']) . '%';
        }
        if (!empty($filters['tag'])) {
            $sql .= ' AND id IN (SELECT recipe_id FROM recipe_tags WHERE tag = ?)';
            $params[] = mb_strtolower(trim((string) $filters['tag']));
        }
        $sql .= ' ORDER BY name COLLATE NOCASE';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $householdId, string $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM recipes WHERE household_id = ? AND id = ?');
        $stmt->execute([$householdId, $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findOrFail(string $householdId, string $id): array
    {
        return $this->find($householdId, $id) ?? throw HttpException::notFound('Recette introuvable.');
    }

    /** @return string[] */
    public function activeIds(string $householdId): array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM recipes WHERE household_id = ? AND is_active = 1');
        $stmt->execute([$householdId]);

        return array_map(static fn (array $r) => $r['id'], $stmt->fetchAll());
    }

    public function create(string $householdId, array $data): array
    {
        return Database::transaction(function () use ($householdId, $data) {
            $name = trim((string) ($data['name'] ?? ''));
            $this->assertName($householdId, $name, null);

            $id = Support::uuid();
            $now = Support::now();
            Database::connection()->prepare(
                'INSERT INTO recipes (id, household_id, name, name_key, photo_url, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $householdId,
                $name,
                Support::normalizeKey($name),
                $this->cleanPhoto($data['photoUrl'] ?? null),
                array_key_exists('isActive', $data) ? (int) (bool) $data['isActive'] : 1,
                $now,
                $now,
            ]);

            $this->syncIngredients($householdId, $id, $data['ingredients'] ?? []);
            $this->syncTags($id, $data['tags'] ?? []);

            return $this->findOrFail($householdId, $id);
        });
    }

    public function update(string $householdId, string $id, array $data): array
    {
        return Database::transaction(function () use ($householdId, $id, $data) {
            $recipe = $this->findOrFail($householdId, $id);
            $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $recipe['name'];
            $this->assertName($householdId, $name, $id);

            Database::connection()->prepare(
                'UPDATE recipes SET name = ?, name_key = ?, photo_url = ?, is_active = ?, updated_at = ?
                 WHERE id = ? AND household_id = ?'
            )->execute([
                $name,
                Support::normalizeKey($name),
                array_key_exists('photoUrl', $data) ? $this->cleanPhoto($data['photoUrl']) : $recipe['photoUrl'],
                array_key_exists('isActive', $data) ? (int) (bool) $data['isActive'] : (int) $recipe['isActive'],
                Support::now(),
                $id,
                $householdId,
            ]);

            if (array_key_exists('ingredients', $data)) {
                $this->syncIngredients($householdId, $id, $data['ingredients']);
            }
            if (array_key_exists('tags', $data)) {
                $this->syncTags($id, $data['tags']);
            }

            return $this->findOrFail($householdId, $id);
        });
    }

    public function delete(string $householdId, string $id): void
    {
        $this->findOrFail($householdId, $id);
        Database::connection()->prepare('DELETE FROM recipes WHERE id = ? AND household_id = ?')
            ->execute([$id, $householdId]);
    }

    /** @return array<string, array<int, array>> ingrédients indexés par recette */
    public function ingredientsForRecipes(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT ri.*, i.name, i.category, i.default_unit
             FROM recipe_ingredients ri
             JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE ri.recipe_id IN ({$placeholders})
             ORDER BY ri.position, i.name COLLATE NOCASE"
        );
        $stmt->execute($recipeIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['recipe_id']][] = [
                'ingredientId' => $row['ingredient_id'],
                'name' => $row['name'],
                'quantity' => $row['quantity'] === null ? null : (float) $row['quantity'],
                'unit' => $row['unit'],
                'category' => $row['category'],
                'categoryLabel' => Units::CATEGORIES[$row['category']] ?? 'Autre',
            ];
        }

        return $grouped;
    }

    private function hydrate(array $row): array
    {
        $ingredients = $this->ingredientsForRecipes([$row['id']])[$row['id']] ?? [];

        $tagStmt = Database::connection()->prepare('SELECT tag FROM recipe_tags WHERE recipe_id = ? ORDER BY tag');
        $tagStmt->execute([$row['id']]);

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'photoUrl' => $row['photo_url'],
            'isActive' => (bool) $row['is_active'],
            'tags' => array_map(static fn (array $r) => $r['tag'], $tagStmt->fetchAll()),
            'ingredients' => $ingredients,
            'ingredientCount' => count($ingredients),
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function assertName(string $householdId, string $name, ?string $exceptId): void
    {
        if ($name === '') {
            throw HttpException::badRequest('Le nom de la recette est obligatoire.', ['name' => 'Obligatoire.']);
        }
        if (mb_strlen($name) > 120) {
            throw HttpException::badRequest('Le nom ne peut dépasser 120 caractères.', ['name' => 'Maximum 120 caractères.']);
        }

        $stmt = Database::connection()->prepare('SELECT id FROM recipes WHERE household_id = ? AND name_key = ?');
        $stmt->execute([$householdId, Support::normalizeKey($name)]);
        $found = $stmt->fetchColumn();
        if ($found !== false && $found !== $exceptId) {
            throw HttpException::conflict("La recette « {$name} » existe déjà.");
        }
    }

    private function syncIngredients(string $householdId, string $recipeId, mixed $lines): void
    {
        if (!is_array($lines)) {
            throw HttpException::badRequest('La liste des ingrédients est invalide.');
        }

        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = ?')->execute([$recipeId]);

        $insert = $pdo->prepare(
            'INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit, position) VALUES (?, ?, ?, ?, ?)'
        );

        $seen = [];
        $position = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $ingredientId = isset($line['ingredientId']) ? (string) $line['ingredientId'] : '';
            if ($ingredientId !== '') {
                $ingredient = $this->ingredients->find($householdId, $ingredientId)
                    ?? throw HttpException::badRequest('Ingrédient inconnu.');
            } else {
                $ingredient = $this->ingredients->findOrCreate(
                    $householdId,
                    (string) ($line['name'] ?? ''),
                    $line['unit'] ?? null,
                    $line['category'] ?? null
                );
            }

            if (isset($seen[$ingredient['id']])) {
                throw HttpException::badRequest(
                    "L'ingrédient « {$ingredient['name']} » est présent deux fois dans la recette."
                );
            }
            $seen[$ingredient['id']] = true;

            $quantity = $line['quantity'] ?? null;
            if ($quantity === '' || $quantity === null) {
                $quantity = null;
            } else {
                if (!is_numeric($quantity)) {
                    throw HttpException::badRequest("Quantité invalide pour « {$ingredient['name']} ».");
                }
                $quantity = (float) $quantity;
                if ($quantity <= 0) {
                    throw HttpException::badRequest("La quantité de « {$ingredient['name']} » doit être positive.");
                }
            }

            $unit = Units::normalizeUnit($line['unit'] ?? null) ?? $ingredient['defaultUnit'];
            if ($quantity !== null && $unit === null) {
                throw HttpException::badRequest("Une unité est requise pour « {$ingredient['name']} ».");
            }

            $insert->execute([$recipeId, $ingredient['id'], $quantity, $quantity === null ? null : $unit, $position++]);
        }
    }

    private function syncTags(string $recipeId, mixed $tags): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM recipe_tags WHERE recipe_id = ?')->execute([$recipeId]);
        if (!is_array($tags)) {
            return;
        }

        $insert = $pdo->prepare('INSERT OR IGNORE INTO recipe_tags (recipe_id, tag) VALUES (?, ?)');
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ($tag !== '') {
                $insert->execute([$recipeId, $tag]);
            }
        }
    }

    private function cleanPhoto(mixed $photoUrl): ?string
    {
        if (!is_string($photoUrl)) {
            return null;
        }
        $photoUrl = trim($photoUrl);

        return $photoUrl === '' ? null : $photoUrl;
    }
}
