<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Food\Auth;
use Food\Database;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;

$request = Request::fromGlobals();

// Fichiers statiques servis directement par le serveur web en production.
if (PHP_SAPI === 'cli-server' && $request->path !== '/' && is_file(__DIR__ . $request->path)) {
    return false;
}

if (!str_starts_with($request->path, '/api')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');

    return;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    Auth::start();
    Database::connection();

    /** @var \Food\Http\Router $router */
    $router = require __DIR__ . '/../src/routes.php';
    $router->dispatch($request)->send();
} catch (HttpException $e) {
    Response::json(
        array_filter([
            'message' => $e->getMessage(),
            'errors' => $e->errors() ?: null,
        ]),
        $e->status()
    )->send();
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(['message' => 'Erreur interne du serveur.'], 500)->send();
}
