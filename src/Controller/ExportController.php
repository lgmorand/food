<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Domain\Units;
use Food\Http\Response;
use Food\Repository\IngredientRepository;
use Food\Repository\RecipeRepository;
use Food\Support;

final class ExportController
{
    public function __construct(
        private readonly RecipeRepository $recipes = new RecipeRepository(),
        private readonly IngredientRepository $ingredients = new IngredientRepository(),
    ) {
    }

    /**
     * Export complet et lisible du catalogue : référentiel d'ingrédients et
     * recettes avec leurs ingrédients.
     */
    public function json(): Response
    {
        $householdId = Auth::requireHouseholdId();

        $ingredients = array_map(static fn (array $ingredient) => [
            'name' => $ingredient['name'],
            'defaultUnit' => $ingredient['defaultUnit'],
            'category' => $ingredient['category'],
            'categoryLabel' => $ingredient['categoryLabel'],
            'usageCount' => $ingredient['usageCount'],
        ], $this->ingredients->all($householdId));

        $recipes = array_map(static fn (array $recipe) => [
            'name' => $recipe['name'],
            'photoUrl' => $recipe['photoUrl'],
            'isActive' => $recipe['isActive'],
            'tags' => $recipe['tags'],
            'ingredients' => array_map(static fn (array $line) => [
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'category' => $line['category'] ?? null,
            ], $recipe['ingredients']),
            'createdAt' => $recipe['createdAt'],
            'updatedAt' => $recipe['updatedAt'],
        ], $this->recipes->all($householdId));

        $payload = [
            'application' => 'Food',
            'formatVersion' => 1,
            'exportedAt' => Support::now(),
            'units' => Units::UNITS,
            'categories' => Units::CATEGORIES,
            'ingredients' => $ingredients,
            'recipes' => $recipes,
        ];

        return Response::download($payload, 'food-export-' . date('Y-m-d') . '.json');
    }
}
