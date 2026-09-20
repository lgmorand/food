<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;
use Food\Repository\ShoppingListRepository;

final class ShoppingListController
{
    public function __construct(
        private readonly ShoppingListRepository $lists = new ShoppingListRepository(),
    ) {
    }

    public function current(): Response
    {
        return Response::json(['shoppingList' => $this->lists->current(Auth::requireHouseholdId())]);
    }

    public function show(Request $request, array $args): Response
    {
        return Response::json(['shoppingList' => $this->lists->find(Auth::requireHouseholdId(), $args['id'])]);
    }

    public function forMenu(Request $request, array $args): Response
    {
        $householdId = Auth::requireHouseholdId();
        $list = $this->lists->findByMenu($householdId, $args['menuId'])
            ?? $this->lists->generateForMenu($householdId, $args['menuId']);

        return Response::json(['shoppingList' => $list]);
    }

    public function toggleItem(Request $request, array $args): Response
    {
        $list = $this->lists->toggleItem(
            Auth::requireHouseholdId(),
            $args['id'],
            $args['itemId'],
            $request->bool('checked', true)
        );

        return Response::json(['shoppingList' => $list]);
    }

    public function addItem(Request $request, array $args): Response
    {
        $list = $this->lists->addManualItem(
            Auth::requireHouseholdId(),
            $args['id'],
            $request->string('label'),
            $request->string('category') ?: null
        );

        return Response::json(['shoppingList' => $list], 201);
    }

    public function deleteItem(Request $request, array $args): Response
    {
        $list = $this->lists->deleteItem(Auth::requireHouseholdId(), $args['id'], $args['itemId']);

        return Response::json(['shoppingList' => $list]);
    }

    public function uncheckAll(Request $request, array $args): Response
    {
        return Response::json(['shoppingList' => $this->lists->uncheckAll(Auth::requireHouseholdId(), $args['id'])]);
    }

    public function export(Request $request, array $args): Response
    {
        $list = $this->lists->find(Auth::requireHouseholdId(), $args['id']);
        if ($list['totalCount'] === 0) {
            throw HttpException::notFound('Liste vide.');
        }

        return Response::json(['text' => $this->lists->toText($list)]);
    }
}
