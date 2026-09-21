<?php

declare(strict_types=1);

namespace Food\Domain;

use Food\Database;
use Food\Http\HttpException;
use Food\Support;
use PDO;

/**
 * Sauvegarde et restauration des données du foyer.
 *
 * Le fichier produit ne contient aucun identifiant technique : recettes,
 * ingrédients, menus et listes de courses sont reliés par leur nom. Il reste
 * donc lisible, modifiable à la main et réimportable dans une base neuve.
 */
final class Backup
{
    public const FORMAT_VERSION = 1;
    public const KIND_CATALOGUE = 'catalogue';
    public const KIND_FULL = 'complet';

    /**
     * Construit la sauvegarde. Le catalogue (ingrédients + recettes) est
     * toujours présent ; les menus et leurs listes de courses ne le sont que
     * pour une sauvegarde complète.
     */
    public static function build(string $householdId, bool $full): array
    {
        $payload = [
            'application' => 'Food',
            'formatVersion' => self::FORMAT_VERSION,
            'kind' => $full ? self::KIND_FULL : self::KIND_CATALOGUE,
            'exportedAt' => Support::now(),
            'units' => Units::UNITS,
            'categories' => Units::CATEGORIES,
            'ingredients' => self::ingredients($householdId),
            'recipes' => self::recipes($householdId),
        ];

        if ($full) {
            $payload['menus'] = self::menus($householdId);
        }

        return $payload;
    }

    /**
     * Restaure un fichier de sauvegarde.
     *
     * @param string $mode « merge » ajoute ce qui manque sans rien supprimer,
     *                     « replace » remplace les données existantes.
     *
     * @return array<string, int> compteurs pour le message de confirmation
     */
    public static function restore(string $householdId, mixed $payload, string $mode): array
    {
        if (!is_array($payload)) {
            throw HttpException::badRequest('Fichier illisible : le contenu attendu est un objet JSON.');
        }
        if (($payload['application'] ?? null) !== 'Food') {
            throw HttpException::badRequest("Ce fichier n'est pas une sauvegarde Food.");
        }
        $version = (int) ($payload['formatVersion'] ?? 0);
        if ($version < 1 || $version > self::FORMAT_VERSION) {
            throw HttpException::badRequest("Format de sauvegarde non pris en charge (version {$version}).");
        }
        if (!in_array($mode, ['merge', 'replace'], true)) {
            throw HttpException::badRequest('Mode d\'import inconnu.');
        }

        $ingredients = self::listOf($payload, 'ingredients');
        $recipes = self::listOf($payload, 'recipes');
        $menus = self::listOf($payload, 'menus');
        $hasMenus = array_key_exists('menus', $payload);

        if ($ingredients === [] && $recipes === [] && $menus === []) {
            throw HttpException::badRequest('Ce fichier ne contient aucune donnée à importer.');
        }

        return Database::transaction(static function (PDO $pdo) use (
            $householdId,
            $mode,
            $ingredients,
            $recipes,
            $menus,
            $hasMenus
        ) {
            $counts = [
                'ingredientsCreated' => 0,
                'ingredientsSkipped' => 0,
                'recipesCreated' => 0,
                'recipesSkipped' => 0,
                'menusCreated' => 0,
                'menusSkipped' => 0,
            ];

            if ($mode === 'replace') {
                self::wipe($pdo, $householdId, $hasMenus);
            }

            foreach ($ingredients as $ingredient) {
                is_array($ingredient) && self::importIngredient($pdo, $householdId, $ingredient, $counts);
            }
            foreach ($recipes as $recipe) {
                is_array($recipe) && self::importRecipe($pdo, $householdId, $recipe, $counts);
            }
            foreach ($menus as $menu) {
                is_array($menu) && self::importMenu($pdo, $householdId, $menu, $counts);
            }

            return $counts;
        });
    }

    /* ------------------------------------------------------------ Lecture */

    private static function ingredients(string $householdId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT name, default_unit, category, created_at FROM ingredients
             WHERE household_id = ? ORDER BY name COLLATE NOCASE'
        );
        $stmt->execute([$householdId]);

        return array_map(static fn (array $row) => [
            'name' => $row['name'],
            'defaultUnit' => $row['default_unit'],
            'category' => $row['category'],
            'categoryLabel' => Units::CATEGORIES[$row['category']] ?? 'Autre',
            'createdAt' => $row['created_at'],
        ], $stmt->fetchAll());
    }

    private static function recipes(string $householdId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM recipes WHERE household_id = ? ORDER BY name COLLATE NOCASE');
        $stmt->execute([$householdId]);
        $rows = $stmt->fetchAll();

        $tags = $pdo->prepare('SELECT tag FROM recipe_tags WHERE recipe_id = ? ORDER BY tag');
        $lines = $pdo->prepare(
            'SELECT i.name, i.category, ri.quantity, ri.unit FROM recipe_ingredients ri
             JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE ri.recipe_id = ? ORDER BY ri.position, i.name COLLATE NOCASE'
        );

        $recipes = [];
        foreach ($rows as $row) {
            $tags->execute([$row['id']]);
            $lines->execute([$row['id']]);

            $recipes[] = [
                'name' => $row['name'],
                'photoUrl' => $row['photo_url'],
                'isActive' => (bool) $row['is_active'],
                'tags' => array_map(static fn (array $r) => $r['tag'], $tags->fetchAll()),
                'ingredients' => array_map(static fn (array $line) => [
                    'name' => $line['name'],
                    'quantity' => $line['quantity'] === null ? null : (float) $line['quantity'],
                    'unit' => $line['unit'],
                    'category' => $line['category'],
                ], $lines->fetchAll()),
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at'],
            ];
        }

        return $recipes;
    }

    private static function menus(string $householdId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM menus WHERE household_id = ? ORDER BY week_start, created_at');
        $stmt->execute([$householdId]);

        $items = $pdo->prepare('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY position');
        $list = $pdo->prepare('SELECT * FROM shopping_lists WHERE menu_id = ?');
        $listItems = $pdo->prepare(
            'SELECT s.*, i.name AS ingredient_name FROM shopping_items s
             LEFT JOIN ingredients i ON i.id = s.ingredient_id
             WHERE s.list_id = ? ORDER BY s.position, s.label COLLATE NOCASE'
        );

        $menus = [];
        foreach ($stmt->fetchAll() as $row) {
            $items->execute([$row['id']]);
            $list->execute([$row['id']]);
            $listRow = $list->fetch();

            $menu = [
                'weekStart' => $row['week_start'],
                'size' => (int) $row['size'],
                'status' => $row['status'],
                'validatedAt' => $row['validated_at'],
                'createdAt' => $row['created_at'],
                'items' => array_map(static fn (array $item) => [
                    'position' => (int) $item['position'],
                    'recipeName' => $item['recipe_name'],
                    'recipePhoto' => $item['recipe_photo'],
                    'locked' => (bool) $item['locked'],
                ], $items->fetchAll()),
                'shoppingList' => null,
            ];

            if ($listRow !== false) {
                $listItems->execute([$listRow['id']]);
                $menu['shoppingList'] = [
                    'createdAt' => $listRow['created_at'],
                    'items' => array_map(static fn (array $item) => [
                        'label' => $item['label'],
                        'ingredientName' => $item['ingredient_name'],
                        'category' => $item['category'],
                        'quantities' => Support::jsonDecodeList($item['quantities']),
                        'sourceRecipes' => Support::jsonDecodeList($item['source_recipes']),
                        'checked' => (bool) $item['checked'],
                        'isManual' => (bool) $item['is_manual'],
                        'position' => (int) $item['position'],
                    ], $listItems->fetchAll()),
                ];
            }

            $menus[] = $menu;
        }

        return $menus;
    }

    /* ---------------------------------------------------------- Écriture */

    /** Vide les données du foyer avant un import en mode remplacement. */
    private static function wipe(PDO $pdo, string $householdId, bool $includeMenus): void
    {
        if ($includeMenus) {
            $pdo->prepare('DELETE FROM menus WHERE household_id = ?')->execute([$householdId]);
        }
        // Les recettes partent en premier : les lignes d'ingrédients les
        // référencent en RESTRICT.
        $pdo->prepare('DELETE FROM recipes WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM ingredients WHERE household_id = ?')->execute([$householdId]);
    }

    private static function importIngredient(PDO $pdo, string $householdId, array $data, array &$counts): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return;
        }

        if (self::findIngredientId($pdo, $householdId, $name) !== null) {
            $counts['ingredientsSkipped']++;

            return;
        }

        $pdo->prepare(
            'INSERT INTO ingredients (id, household_id, name, name_key, default_unit, category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            Support::uuid(),
            $householdId,
            $name,
            Support::normalizeKey($name),
            Units::normalizeUnit(self::text($data['defaultUnit'] ?? null)),
            Units::normalizeCategory(self::text($data['category'] ?? null)),
            self::date($data['createdAt'] ?? null),
        ]);
        $counts['ingredientsCreated']++;
    }

    private static function importRecipe(PDO $pdo, string $householdId, array $data, array &$counts): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return;
        }
        if (self::findRecipeId($pdo, $householdId, $name) !== null) {
            $counts['recipesSkipped']++;

            return;
        }

        $id = Support::uuid();
        $createdAt = self::date($data['createdAt'] ?? null);
        $pdo->prepare(
            'INSERT INTO recipes (id, household_id, name, name_key, photo_url, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $householdId,
            $name,
            Support::normalizeKey($name),
            self::text($data['photoUrl'] ?? null),
            array_key_exists('isActive', $data) ? (int) (bool) $data['isActive'] : 1,
            $createdAt,
            self::date($data['updatedAt'] ?? null, $createdAt),
        ]);
        $counts['recipesCreated']++;

        $tagStmt = $pdo->prepare('INSERT OR IGNORE INTO recipe_tags (recipe_id, tag) VALUES (?, ?)');
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ($tag !== '') {
                $tagStmt->execute([$id, $tag]);
            }
        }

        $lineStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit, position)
             VALUES (?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach (is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineName = trim((string) ($line['name'] ?? ''));
            if ($lineName === '') {
                continue;
            }

            $ingredientId = self::findIngredientId($pdo, $householdId, $lineName);
            if ($ingredientId === null) {
                self::importIngredient($pdo, $householdId, [
                    'name' => $lineName,
                    'defaultUnit' => $line['unit'] ?? null,
                    'category' => $line['category'] ?? null,
                ], $counts);
                $ingredientId = self::findIngredientId($pdo, $householdId, $lineName);
            }
            if ($ingredientId === null) {
                continue;
            }

            $quantity = $line['quantity'] ?? null;
            $quantity = is_numeric($quantity) && (float) $quantity > 0 ? (float) $quantity : null;
            $unit = Units::normalizeUnit(self::text($line['unit'] ?? null));

            $lineStmt->execute([$id, $ingredientId, $quantity, $quantity === null ? null : $unit, $position++]);
        }
    }

    private static function importMenu(PDO $pdo, string $householdId, array $data, array &$counts): void
    {
        $weekStart = Support::mondayOf(substr(self::date($data['weekStart'] ?? null), 0, 10));
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $createdAt = self::date($data['createdAt'] ?? null);
        $status = ($data['status'] ?? 'draft') === 'validated' ? 'validated' : 'draft';

        // Réimporter deux fois le même fichier ne doit pas dupliquer les menus.
        $exists = $pdo->prepare('SELECT id FROM menus WHERE household_id = ? AND week_start = ? AND created_at = ?');
        $exists->execute([$householdId, $weekStart, $createdAt]);
        if ($exists->fetchColumn() !== false) {
            $counts['menusSkipped']++;

            return;
        }

        $menuId = Support::uuid();
        $pdo->prepare(
            'INSERT INTO menus (id, household_id, week_start, size, status, validated_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $menuId,
            $householdId,
            $weekStart,
            max(1, (int) ($data['size'] ?? count($items))),
            $status,
            $status === 'validated' ? self::date($data['validatedAt'] ?? null, $createdAt) : null,
            $createdAt,
        ]);
        $counts['menusCreated']++;

        $itemStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO menu_items (menu_id, position, recipe_id, recipe_name, recipe_photo, locked)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $recipeName = trim((string) ($item['recipeName'] ?? ''));
            if ($recipeName === '') {
                continue;
            }
            $itemStmt->execute([
                $menuId,
                isset($item['position']) ? (int) $item['position'] : $position,
                self::findRecipeId($pdo, $householdId, $recipeName),
                $recipeName,
                self::text($item['recipePhoto'] ?? null),
                !empty($item['locked']) ? 1 : 0,
            ]);
            $position++;
        }

        $list = $data['shoppingList'] ?? null;
        if (!is_array($list)) {
            return;
        }

        $listId = Support::uuid();
        $pdo->prepare('INSERT INTO shopping_lists (id, menu_id, household_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$listId, $menuId, $householdId, self::date($list['createdAt'] ?? null, $createdAt)]);

        $itemInsert = $pdo->prepare(
            'INSERT INTO shopping_items
                (id, list_id, ingredient_id, label, category, quantities, source_recipes, checked, is_manual, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach (is_array($list['items'] ?? null) ? $list['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $ingredientName = self::text($item['ingredientName'] ?? null);

            $itemInsert->execute([
                Support::uuid(),
                $listId,
                $ingredientName === null ? null : self::findIngredientId($pdo, $householdId, $ingredientName),
                $label,
                Units::normalizeCategory(self::text($item['category'] ?? null)),
                json_encode(is_array($item['quantities'] ?? null) ? $item['quantities'] : [], JSON_UNESCAPED_UNICODE),
                json_encode(is_array($item['sourceRecipes'] ?? null) ? $item['sourceRecipes'] : [], JSON_UNESCAPED_UNICODE),
                !empty($item['checked']) ? 1 : 0,
                !empty($item['isManual']) ? 1 : 0,
                isset($item['position']) ? (int) $item['position'] : $position,
            ]);
            $position++;
        }
    }

    /* ------------------------------------------------------------- Outils */

    private static function findIngredientId(PDO $pdo, string $householdId, string $name): ?string
    {
        $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE household_id = ? AND name_key = ?');
        $stmt->execute([$householdId, Support::normalizeKey($name)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    private static function findRecipeId(PDO $pdo, string $householdId, string $name): ?string
    {
        $stmt = $pdo->prepare('SELECT id FROM recipes WHERE household_id = ? AND name_key = ?');
        $stmt->execute([$householdId, Support::normalizeKey($name)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    /** @param list<mixed>|mixed $payload */
    private static function listOf(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function date(mixed $value, ?string $fallback = null): string
    {
        $value = self::text($value);
        if ($value === null) {
            return $fallback ?? Support::now();
        }
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return $fallback ?? Support::now();
        }
    }
}
