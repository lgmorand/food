<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Domain\Units;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;

final class AuthController
{
    public function register(Request $request): Response
    {
        $user = Auth::register(
            $request->string('email'),
            (string) $request->input('password', ''),
            $request->string('displayName'),
            $request->string('invitationToken') ?: null
        );

        return Response::json(['user' => $user], 201);
    }

    public function login(Request $request): Response
    {
        $user = Auth::attempt($request->string('email'), (string) $request->input('password', ''));

        return Response::json(['user' => $user]);
    }

    public function logout(): Response
    {
        Auth::logout();

        return Response::noContent();
    }

    public function me(): Response
    {
        $user = Auth::user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return Response::json([
            'user' => $user,
            'units' => Units::UNITS,
            'categories' => Units::CATEGORIES,
            'categoryOrder' => Units::CATEGORY_ORDER,
        ]);
    }

    public function invite(): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::json(Auth::createInvitation($householdId), 201);
    }
}
