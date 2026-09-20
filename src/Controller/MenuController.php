<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Http\Request;
use Food\Http\Response;
use Food\Repository\MenuRepository;
use Food\Repository\ShoppingListRepository;
use Food\Support;

final class MenuController
{
    public function __construct(
        private readonly MenuRepository $menus = new MenuRepository(),
        private readonly ShoppingListRepository $lists = new ShoppingListRepository(),
    ) {
    }

    public function current(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();
        $weekStart = Support::mondayOf($request->queryString('weekStart') ?: null);

        $validated = $this->menus->validatedForWeek($householdId, $weekStart);

        return Response::json([
            'weekStart' => $weekStart,
            'draft' => $this->menus->currentDraft($householdId, $weekStart),
            'validated' => $validated,
            'shoppingList' => $validated === null
                ? null
                : $this->lists->findByMenu($householdId, $validated['id']),
        ]);
    }

    public function history(): Response
    {
        return Response::json(['menus' => $this->menus->history(Auth::requireHouseholdId())]);
    }

    public function show(Request $request, array $args): Response
    {
        return Response::json(['menu' => $this->menus->findOrFail(Auth::requireHouseholdId(), $args['id'])]);
    }

    public function generate(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();
        $menu = $this->menus->generate(
            $householdId,
            $request->int('size', 5),
            $request->string('weekStart') ?: null
        );

        return Response::json($this->withWarning($menu), 201);
    }

    public function regenerate(Request $request, array $args): Response
    {
        $menu = $this->menus->regenerate(Auth::requireHouseholdId(), $args['id']);

        return Response::json($this->withWarning($menu));
    }

    public function replaceItem(Request $request, array $args): Response
    {
        $menu = $this->menus->replaceItem(Auth::requireHouseholdId(), $args['id'], (int) $args['position']);

        return Response::json($this->withWarning($menu));
    }

    public function removeItem(Request $request, array $args): Response
    {
        $menu = $this->menus->removeItem(Auth::requireHouseholdId(), $args['id'], (int) $args['position']);

        return Response::json($this->withWarning($menu));
    }

    public function assignItem(Request $request, array $args): Response
    {
        $menu = $this->menus->assignItem(
            Auth::requireHouseholdId(),
            $args['id'],
            (int) $args['position'],
            $request->string('recipeId')
        );

        return Response::json($this->withWarning($menu));
    }

    public function lockItem(Request $request, array $args): Response
    {
        $menu = $this->menus->toggleLock(
            Auth::requireHouseholdId(),
            $args['id'],
            (int) $args['position'],
            $request->bool('locked', true)
        );

        return Response::json($this->withWarning($menu));
    }

    public function validateMenu(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();
        $menu = $this->menus->validate($householdId, $args['id']);
        $list = $this->lists->generateForMenu($householdId, $menu['id']);

        return Response::json(['menu' => $menu, 'shoppingList' => $list]);
    }

    public function replay(Request $request, array $args): Response
    {
        $menu = $this->menus->replay(
            Auth::requireHouseholdId(),
            $args['id'],
            $request->string('weekStart') ?: null
        );

        return Response::json($this->withWarning($menu), 201);
    }

    public function destroy(Request $request, array $args): Response
    {
        $this->menus->delete(Auth::requireHouseholdId(), $args['id']);

        return Response::noContent();
    }

    private function withWarning(array $menu): array
    {
        $filled = count($menu['recipeIds']);
        $warning = null;
        if ($filled < $menu['size']) {
            $warning = "Seulement {$filled} recette(s) disponible(s) : ajoutez des recettes au catalogue "
                . 'pour compléter le menu.';
        }

        return ['menu' => $menu, 'warning' => $warning];
    }
}
