<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Http\Request;
use Food\Http\Response;
use Food\Repository\IngredientRepository;

final class IngredientController
{
    public function __construct(
        private readonly IngredientRepository $ingredients = new IngredientRepository(),
    ) {
    }

    public function index(): Response
    {
        return Response::json(['ingredients' => $this->ingredients->all(Auth::requireHouseholdId())]);
    }

    public function store(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();
        $ingredient = $this->ingredients->create(
            $householdId,
            $request->string('name'),
            $request->string('defaultUnit') ?: null,
            $request->string('category') ?: null
        );

        return Response::json(['ingredient' => $ingredient], 201);
    }

    public function update(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json(['ingredient' => $this->ingredients->update($householdId, $args['id'], $request->all())]);
    }

    public function destroy(Request $request, array $args): Response
    {
        $this->ingredients->delete(Auth::requireHouseholdId(), $args['id']);

        return Response::noContent();
    }
}
