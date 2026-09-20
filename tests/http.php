<?php

declare(strict_types=1);

/**
 * Test de bout en bout de l'API HTTP.
 * Usage : php tests/http.php [base-url]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8321', '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'food-cookies');
$failures = [];
$passed = 0;

function request(string $method, string $path, ?array $body = null): array
{
    // Le serveur de développement PHP sous Windows réinitialise parfois la
    // connexion : on retente uniquement les erreurs de transport.
    $lastError = '';
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            return rawRequest($method, $path, $body);
        } catch (RuntimeException $e) {
            $lastError = $e->getMessage();
            usleep(150000);
        }
    }

    throw new RuntimeException($lastError);
}

function rawRequest(string $method, string $path, ?array $body = null): array
{
    global $base, $cookieJar;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('cURL: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['status' => $status, 'body' => json_decode((string) $response, true)];
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  OK   {$name}\n";
    } else {
        $failures[] = $name . ($detail !== '' ? " ({$detail})" : '');
        echo "  FAIL {$name} {$detail}\n";
    }
}

echo "Tests HTTP sur {$base}\n" . str_repeat('-', 60) . "\n";

$email = 'e2e-' . bin2hex(random_bytes(4)) . '@example.com';

$res = request('GET', '/api/auth/me');
check('Accès non authentifié refusé', $res['status'] === 401);

$res = request('POST', '/api/auth/register', [
    'email' => $email, 'password' => 'motdepasse1', 'displayName' => 'E2E',
]);
check('Inscription', $res['status'] === 201 && isset($res['body']['user']['id']), (string) $res['status']);

$res = request('GET', '/api/auth/me');
check('Session active', $res['status'] === 200 && $res['body']['user']['email'] === $email);
check('Référentiels exposés', isset($res['body']['units']['kg'], $res['body']['categories']['epicerie']));

$catalog = [
    ['Poulet rôti', [['name' => 'Poulet', 'quantity' => 1.2, 'unit' => 'kg', 'category' => 'viande_poisson']]],
    ['Quiche', [
        ['name' => 'Crème fraîche', 'quantity' => 200, 'unit' => 'ml', 'category' => 'cremerie'],
        ['name' => 'Lardons', 'quantity' => 150, 'unit' => 'g', 'category' => 'viande_poisson'],
    ]],
    ['Salade', [['name' => 'Tomates', 'quantity' => 500, 'unit' => 'g', 'category' => 'fruits_legumes']]],
    ['Gratin', [['name' => 'Pommes de terre', 'quantity' => 800, 'unit' => 'g', 'category' => 'fruits_legumes']]],
    ['Omelette', [['name' => 'Œufs', 'quantity' => 6, 'unit' => 'piece', 'category' => 'cremerie']]],
    ['Soupe', [['name' => 'Carottes', 'quantity' => 400, 'unit' => 'g', 'category' => 'fruits_legumes']]],
    ['Risotto', [['name' => 'Riz', 'quantity' => 300, 'unit' => 'g', 'category' => 'epicerie']]],
];
$created = 0;
foreach ($catalog as [$name, $lines]) {
    $res = request('POST', '/api/recipes', ['name' => $name, 'ingredients' => $lines]);
    // 409 = recette déjà créée par une tentative rejouée après un reset réseau.
    if ($res['status'] === 201 || $res['status'] === 409) {
        $created++;
    }
}
check('Création des 7 recettes', $created === 7, "créées: {$created}");

$res = request('GET', '/api/recipes');
check('Listing des recettes', count($res['body']['recipes']) === 7);

$res = request('GET', '/api/recipes?search=quic');
check('Recherche par nom', count($res['body']['recipes']) === 1);

$res = request('POST', '/api/menus/generate', ['size' => 6]);
$menu = $res['body']['menu'] ?? [];
check('Génération d\'un menu de 6', $res['status'] === 201 && count($menu['items'] ?? []) === 6);
check('Aucun doublon dans le menu', count(array_unique($menu['recipeIds'])) === 6);

$before = $menu['items'][1]['recipeId'];
$res = request('POST', "/api/menus/{$menu['id']}/items/1/replace");
$menu = $res['body']['menu'];
check('Remplacement d\'une recette', $menu['items'][1]['recipeId'] !== $before);

$before = $menu['items'][0]['recipeId'];
$res = request('DELETE', "/api/menus/{$menu['id']}/items/0");
$menu = $res['body']['menu'];
check('Suppression -> nouveau tirage', $menu['items'][0]['recipeId'] !== $before && count($menu['items']) === 6);

$res = request('POST', "/api/menus/{$menu['id']}/items/2/lock", ['locked' => true]);
$locked = $res['body']['menu']['items'][2]['recipeId'];
$res = request('POST', "/api/menus/{$menu['id']}/regenerate");
check('Verrou respecté à la régénération', $res['body']['menu']['items'][2]['recipeId'] === $locked);

$res = request('POST', "/api/menus/{$menu['id']}/validate");
check('Validation du menu', $res['status'] === 200 && $res['body']['menu']['status'] === 'validated');
$list = $res['body']['shoppingList'];
check('Liste de courses générée', ($list['totalCount'] ?? 0) > 0, 'items: ' . ($list['totalCount'] ?? 0));
check('Liste groupée par rayon', count($list['groups']) > 1);

$res = request('GET', '/api/shopping-list/current');
check('Liste courante accessible', $res['body']['shoppingList']['id'] === $list['id']);

$itemId = $list['groups'][0]['items'][0]['id'];
$res = request('PATCH', "/api/shopping-lists/{$list['id']}/items/{$itemId}", ['checked' => true]);
check('Cocher un article', $res['body']['shoppingList']['checkedCount'] === 1);

$res = request('POST', "/api/shopping-lists/{$list['id']}/items", ['label' => 'Sacs poubelle', 'category' => 'entretien']);
check('Ajout d\'un article libre', $res['status'] === 201 && $res['body']['shoppingList']['totalCount'] === $list['totalCount'] + 1);

$res = request('GET', "/api/shopping-lists/{$list['id']}/export");
check('Export texte', str_contains($res['body']['text'] ?? '', 'Liste de courses'));

$res = request('GET', '/api/menus/history');
check('Historique', count($res['body']['menus']) === 1);

$res = request('GET', '/api/menus/current');
check('Semaine en cours : menu validé + liste', $res['body']['validated'] !== null && $res['body']['shoppingList'] !== null);

$res = request('POST', "/api/menus/{$menu['id']}/items/0/replace");
check('Menu validé non modifiable', $res['status'] === 409);

$res = request('POST', '/api/auth/logout');
check('Déconnexion', $res['status'] === 204);

$res = request('GET', '/api/recipes');
check('Accès refusé après déconnexion', $res['status'] === 401);

$res = request('POST', '/api/auth/login', ['email' => $email, 'password' => 'mauvais']);
check('Mauvais mot de passe refusé', $res['status'] === 401);

$res = request('POST', '/api/auth/login', ['email' => $email, 'password' => 'motdepasse1']);
check('Reconnexion', $res['status'] === 200);

echo "\n" . str_repeat('-', 60) . "\n";
@unlink($cookieJar);

if ($failures === []) {
    echo "Tous les tests HTTP passent ({$passed} vérifications).\n";
    exit(0);
}
echo count($failures) . " échec(s) :\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
