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
    public function setup(Request $request): Response
    {
        $user = Auth::setup(
            (string) $request->input('password', ''),
            $request->string('username') ?: Auth::DEFAULT_USERNAME
        );

        return Response::json(['user' => $user], 201);
    }

    public function status(): Response
    {
        return Response::json([
            'needsSetup' => Auth::needsSetup(),
            'defaultUsername' => Auth::DEFAULT_USERNAME,
            'authenticated' => Auth::user() !== null,
        ]);
    }

    public function login(Request $request): Response
    {
        $user = Auth::attempt($request->string('username'), (string) $request->input('password', ''));

        return Response::json(['user' => $user]);
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::requireUser();
        Auth::changePassword(
            $user['id'],
            (string) $request->input('currentPassword', ''),
            (string) $request->input('newPassword', '')
        );

        return Response::noContent();
    }

    public function changeUsername(Request $request): Response
    {
        $user = Auth::requireUser();
        $updated = Auth::changeUsername(
            $user['id'],
            (string) $request->input('currentPassword', ''),
            $request->string('username')
        );

        return Response::json(['user' => $updated]);
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
}
