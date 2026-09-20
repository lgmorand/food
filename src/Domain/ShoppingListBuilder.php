<?php

declare(strict_types=1);

namespace Food\Domain;

use Food\Repository\RecipeRepository;

/**
 * Agrégation des ingrédients d'un menu en une liste de courses (cf. SPECS.md §7).
 */
final class ShoppingListBuilder
{
    public function __construct(
        private readonly RecipeRepository $recipes = new RecipeRepository(),
    ) {
    }

    /**
     * @param array<int, array{recipeId:?string, recipeName:string}> $menuItems
     * @return array<int, array{ingredientId:string, label:string, category:string, quantities:array, sourceRecipes:string[]}>
     */
    public function build(array $menuItems): array
    {
        $recipeIds = array_values(array_filter(array_map(
            static fn (array $item) => $item['recipeId'] ?? null,
            $menuItems
        )));

        $names = [];
        foreach ($menuItems as $item) {
            if (!empty($item['recipeId'])) {
                $names[$item['recipeId']] = $item['recipeName'];
            }
        }

        $byRecipe = $this->recipes->ingredientsForRecipes($recipeIds);

        /** @var array<string, array> $aggregated */
        $aggregated = [];
        foreach ($recipeIds as $recipeId) {
            foreach ($byRecipe[$recipeId] ?? [] as $line) {
                $key = $line['ingredientId'];
                $aggregated[$key] ??= [
                    'ingredientId' => $key,
                    'label' => $line['name'],
                    'category' => $line['category'],
                    'families' => [],
                    'hasUnquantified' => false,
                    'sourceRecipes' => [],
                ];

                $recipeName = $names[$recipeId] ?? '';
                if ($recipeName !== '' && !in_array($recipeName, $aggregated[$key]['sourceRecipes'], true)) {
                    $aggregated[$key]['sourceRecipes'][] = $recipeName;
                }

                if ($line['quantity'] === null || !Units::isValid($line['unit'])) {
                    $aggregated[$key]['hasUnquantified'] = true;
                    continue;
                }

                $family = Units::family($line['unit']);
                $aggregated[$key]['families'][$family] =
                    ($aggregated[$key]['families'][$family] ?? 0.0)
                    + Units::toBase($line['quantity'], $line['unit']);
            }
        }

        $items = [];
        foreach ($aggregated as $entry) {
            $families = $entry['families'];
            // Ordre d'affichage stable, indépendant de l'ordre des recettes.
            uksort($families, static function (string $a, string $b): int {
                $order = ['masse', 'volume', 'piece'];
                $ia = array_search($a, $order, true);
                $ib = array_search($b, $order, true);
                $ia = $ia === false ? PHP_INT_MAX : $ia;
                $ib = $ib === false ? PHP_INT_MAX : $ib;

                return $ia === $ib ? strcmp($a, $b) : $ia <=> $ib;
            });

            $quantities = [];
            foreach ($families as $family => $baseQuantity) {
                $human = Units::humanize($baseQuantity, $family);
                $quantities[] = [
                    'quantity' => $human['quantity'],
                    'unit' => $human['unit'],
                    'display' => Units::format($human['quantity'], $human['unit']),
                ];
            }

            $items[] = [
                'ingredientId' => $entry['ingredientId'],
                'label' => $entry['label'],
                'category' => $entry['category'],
                'quantities' => $quantities,
                'sourceRecipes' => $entry['sourceRecipes'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $orderA = array_search($a['category'], Units::CATEGORY_ORDER, true);
            $orderB = array_search($b['category'], Units::CATEGORY_ORDER, true);
            $orderA = $orderA === false ? PHP_INT_MAX : $orderA;
            $orderB = $orderB === false ? PHP_INT_MAX : $orderB;

            return $orderA === $orderB
                ? strcasecmp($a['label'], $b['label'])
                : $orderA <=> $orderB;
        });

        return $items;
    }
}
