<?php

declare(strict_types=1);

namespace Food\Repository;

use Food\Database;
use Food\Domain\Units;
use Food\Http\HttpException;
use Food\Support;

final class IngredientRepository
{
    public function all(string $householdId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*, (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.ingredient_id = i.id) AS usage_count
             FROM ingredients i WHERE i.household_id = ? ORDER BY i.name COLLATE NOCASE'
        );
        $stmt->execute([$householdId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function find(string $householdId, string $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ingredients WHERE household_id = ? AND id = ?');
        $stmt->execute([$householdId, $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByName(string $householdId, string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ingredients WHERE household_id = ? AND name_key = ?');
        $stmt->execute([$householdId, Support::normalizeKey($name)]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** Retourne l'ingrédient existant ou le crée à la volée. */
    public function findOrCreate(string $householdId, string $name, ?string $unit = null, ?string $category = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw HttpException::badRequest("Le nom de l'ingrédient est obligatoire.");
        }

        $existing = $this->findByName($householdId, $name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create($householdId, $name, $unit, $category);
    }

    public function create(string $householdId, string $name, ?string $unit = null, ?string $category = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw HttpException::badRequest("Le nom de l'ingrédient est obligatoire.");
        }
        if ($this->findByName($householdId, $name) !== null) {
            throw HttpException::conflict("L'ingrédient « {$name} » existe déjà.");
        }

        $id = Support::uuid();
        Database::connection()->prepare(
            'INSERT INTO ingredients (id, household_id, name, name_key, default_unit, category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $householdId,
            $name,
            Support::normalizeKey($name),
            Units::normalizeUnit($unit),
            Units::normalizeCategory($category),
            Support::now(),
        ]);

        return $this->find($householdId, $id) ?? throw HttpException::notFound();
    }

    public function update(string $householdId, string $id, array $data): array
    {
        $ingredient = $this->find($householdId, $id) ?? throw HttpException::notFound('Ingrédient introuvable.');

        $name = isset($data['name']) ? trim((string) $data['name']) : $ingredient['name'];
        if ($name === '') {
            throw HttpException::badRequest("Le nom de l'ingrédient est obligatoire.");
        }
        $duplicate = $this->findByName($householdId, $name);
        if ($duplicate !== null && $duplicate['id'] !== $id) {
            throw HttpException::conflict("Un autre ingrédient porte déjà le nom « {$name} ».");
        }

        Database::connection()->prepare(
            'UPDATE ingredients SET name = ?, name_key = ?, default_unit = ?, category = ? WHERE id = ? AND household_id = ?'
        )->execute([
            $name,
            Support::normalizeKey($name),
            array_key_exists('defaultUnit', $data) ? Units::normalizeUnit($data['defaultUnit']) : $ingredient['defaultUnit'],
            array_key_exists('category', $data) ? Units::normalizeCategory($data['category']) : $ingredient['category'],
            $id,
            $householdId,
        ]);

        return $this->find($householdId, $id) ?? throw HttpException::notFound();
    }

    public function delete(string $householdId, string $id): void
    {
        $this->find($householdId, $id) ?? throw HttpException::notFound('Ingrédient introuvable.');

        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM recipe_ingredients WHERE ingredient_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw HttpException::conflict(
                "Cet ingrédient est utilisé par au moins une recette : renommez-le plutôt que de le supprimer."
            );
        }

        Database::connection()->prepare('DELETE FROM ingredients WHERE id = ? AND household_id = ?')
            ->execute([$id, $householdId]);
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'defaultUnit' => $row['default_unit'],
            'category' => $row['category'],
            'categoryLabel' => Units::CATEGORIES[$row['category']] ?? 'Autre',
            'usageCount' => isset($row['usage_count']) ? (int) $row['usage_count'] : null,
        ];
    }
}
