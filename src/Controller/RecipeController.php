<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Http\Request;
use Food\Http\Response;
use Food\Repository\RecipeRepository;

final class RecipeController
{
    public function __construct(
        private readonly RecipeRepository $recipes = new RecipeRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json([
            'recipes' => $this->recipes->all($householdId, [
                'search' => $request->queryString('search'),
                'tag' => $request->queryString('tag'),
                'onlyActive' => $request->queryString('onlyActive') === '1',
            ]),
        ]);
    }

    public function show(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json(['recipe' => $this->recipes->findOrFail($householdId, $args['id'])]);
    }

    public function store(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json(['recipe' => $this->recipes->create($householdId, $request->all())], 201);
    }

    public function update(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json(['recipe' => $this->recipes->update($householdId, $args['id'], $request->all())]);
    }

    public function destroy(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();
        $this->recipes->delete($householdId, $args['id']);

        return Response::noContent();
    }
}
