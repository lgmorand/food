<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Food\Auth;
use Food\Database;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;

$request = Request::fromGlobals();

// Ressources statiques : servies par le serveur web en production, par le
// front controller quand la racine du site n'est pas public/ (ou en dev).
if (preg_match('#^/(assets|uploads)/([A-Za-z0-9._-]+)$#', $request->path, $matches) === 1) {
    $file = __DIR__ . '/' . $matches[1] . '/' . $matches[2];
    if (!str_contains($matches[2], '..') && is_file($file)) {
        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
        ];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset($types[$extension])) {
            header('Content-Type: ' . $types[$extension]);
            header('Content-Length: ' . (string) filesize($file));
            header('X-Content-Type-Options: nosniff');
            readfile($file);

            return;
        }
    }

    http_response_code(404);

    return;
}

if (!str_starts_with($request->path, '/api')) {
    // L'application peut vivre dans un sous-dossier (https://exemple.fr/food) :
    // <base> permet aux ressources et aux appels d'API de rester relatifs.
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $html = str_replace('{{BASE}}', htmlspecialchars($request->basePath . '/', ENT_QUOTES), $html);

    header('Content-Type: text/html; charset=utf-8');
    echo $html;

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
