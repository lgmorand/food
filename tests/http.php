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

$username = 'e2e' . bin2hex(random_bytes(3));

$res = request('GET', '/api/auth/me');
check('Accès non authentifié refusé', $res['status'] === 401);

$res = request('GET', '/api/auth/status');
check('Statut public disponible', $res['status'] === 200 && $res['body']['needsSetup'] === true, (string) $res['status']);

$res = request('POST', '/api/auth/setup', ['username' => $username, 'password' => 'motdepasse1']);
check('Création du compte unique', $res['status'] === 201 && isset($res['body']['user']['id']), (string) $res['status']);

$res = request('POST', '/api/auth/setup', ['username' => 'autre', 'password' => 'motdepasse1']);
check('Second compte refusé', $res['status'] === 409, (string) $res['status']);

$res = request('GET', '/api/auth/me');
check('Session active', $res['status'] === 200 && $res['body']['user']['username'] === $username);
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

$res = request('POST', '/api/auth/login', ['username' => $username, 'password' => 'mauvais']);
check('Mauvais mot de passe refusé', $res['status'] === 401);

$res = request('POST', '/api/auth/login', ['username' => $username, 'password' => 'motdepasse1']);
check('Reconnexion', $res['status'] === 200);

$res = request('GET', '/api/auth/status');
check('Configuration déjà faite', $res['status'] === 200 && $res['body']['needsSetup'] === false);

$res = request('POST', '/api/auth/password', ['currentPassword' => 'mauvais', 'newPassword' => 'motdepasse2']);
check('Changement de mot de passe protégé', $res['status'] === 422, (string) $res['status']);

$res = request('POST', '/api/auth/password', ['currentPassword' => 'motdepasse1', 'newPassword' => 'motdepasse2']);
check('Changement de mot de passe', $res['status'] === 204, (string) $res['status']);

request('POST', '/api/auth/logout');
$res = request('POST', '/api/auth/login', ['username' => $username, 'password' => 'motdepasse2']);
check('Connexion avec le nouveau mot de passe', $res['status'] === 200);

$res = request('GET', '/api/export');
check(
    'Export JSON du catalogue',
    $res['status'] === 200
        && ($res['body']['formatVersion'] ?? null) === 1
        && count($res['body']['recipes'] ?? []) === 7
        && count($res['body']['ingredients'] ?? []) > 0
        && isset($res['body']['recipes'][0]['ingredients'][0]['name']),
    (string) $res['status']
);

$res = request('GET', '/api/backup');
$sauvegarde = $res['body'];
check(
    'Sauvegarde complète',
    $res['status'] === 200
        && ($sauvegarde['kind'] ?? null) === 'complet'
        && count($sauvegarde['recipes'] ?? []) === 7
        && count($sauvegarde['menus'] ?? []) > 0,
    (string) $res['status']
);

$res = request('POST', '/api/import', ['mode' => 'merge', 'data' => $sauvegarde]);
check(
    'Import sans doublon (mode complémentaire)',
    $res['status'] === 200
        && ($res['body']['imported']['recipesCreated'] ?? null) === 0
        && ($res['body']['imported']['recipesSkipped'] ?? null) === 7,
    json_encode($res['body']['imported'] ?? null)
);

$res = request('GET', '/api/recipes');
check('Catalogue inchangé après import', count($res['body']['recipes'] ?? []) === 7);

$res = request('POST', '/api/import', ['mode' => 'replace', 'data' => $sauvegarde]);
check(
    'Import en mode remplacement',
    $res['status'] === 200 && ($res['body']['imported']['recipesCreated'] ?? null) === 7,
    json_encode($res['body']['imported'] ?? null)
);

$res = request('GET', '/api/recipes');
check('Catalogue restauré à l\'identique', count($res['body']['recipes'] ?? []) === 7);

$res = request('POST', '/api/import', ['mode' => 'merge', 'data' => ['application' => 'Autre']]);
check('Fichier étranger refusé', $res['status'] === 422, (string) $res['status']);

$res = request('POST', '/api/import', ['mode' => 'merge']);
check('Import sans fichier refusé', $res['status'] === 422, (string) $res['status']);

$res = request('POST', '/api/auth/username', ['currentPassword' => 'motdepasse2', 'username' => 'morand']);
check(
    "Changement d'identifiant",
    $res['status'] === 200 && ($res['body']['user']['username'] ?? null) === 'morand',
    (string) $res['status']
);

request('POST', '/api/auth/logout');
$res = request('POST', '/api/auth/login', ['username' => 'morand', 'password' => 'motdepasse2']);
check('Connexion avec le nouvel identifiant', $res['status'] === 200);

request('POST', '/api/auth/logout');
$res = request('GET', '/api/export');
check('Export refusé hors session', $res['status'] === 401, (string) $res['status']);

$res = request('POST', '/api/import', ['mode' => 'merge', 'data' => ['application' => 'Food']]);
check('Import refusé hors session', $res['status'] === 401, (string) $res['status']);

// Protection anti brute force : 5 échecs puis blocage (identifiant dédié pour
// ne pas verrouiller le compte utilisé par les vérifications précédentes).
// Le nombre exact d'essais avant blocage est vérifié par tests/run.php : ici
// une requête rejouée après une coupure pourrait compter un échec de plus.
$cible = 'brute' . bin2hex(random_bytes(3));
$statuts = [];
for ($i = 1; $i <= 6; $i++) {
    $statuts[] = request('POST', '/api/auth/login', ['username' => $cible, 'password' => 'mauvais'])['status'];
}
check(
    'Échecs de connexion refusés en 401',
    $statuts[0] === 401 && $statuts[1] === 401 && $statuts[2] === 401,
    implode(',', $statuts)
);
check('Blocage atteint (429)', $statuts[5] === 429, implode(',', $statuts));

$res = request('POST', '/api/auth/login', ['username' => $cible, 'password' => 'mauvais']);
check(
    'Message de blocage explicite',
    $res['status'] === 429 && str_contains((string) ($res['body']['message'] ?? ''), 'Trop de tentatives'),
    (string) ($res['body']['message'] ?? '')
);

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
