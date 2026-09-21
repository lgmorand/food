<?php

declare(strict_types=1);

/**
 * Crée le compte de l'application (s'il n'existe pas) avec un catalogue
 * de recettes de démarrage.
 * Usage : php bin/seed.php [motdepasse] [identifiant]
 */

require __DIR__ . '/../bootstrap.php';

use Food\Auth;
use Food\Repository\RecipeRepository;

$password = $argv[1] ?? 'motdepasse1';
$username = $argv[2] ?? Auth::DEFAULT_USERNAME;

$recipes = new RecipeRepository();

try {
    $user = Auth::needsSetup() ? Auth::setup($password, $username) : Auth::attempt($username, $password);
} catch (Throwable $e) {
    echo "Impossible d'accéder au compte ({$e->getMessage()}).\n";
    exit(1);
}

$catalog = [
    'Pâtes bolognaise' => [
        ['name' => 'Pâtes', 'quantity' => 500, 'unit' => 'g', 'category' => 'epicerie'],
        ['name' => 'Bœuf haché', 'quantity' => 400, 'unit' => 'g', 'category' => 'viande_poisson'],
        ['name' => 'Tomates pelées', 'quantity' => 1, 'unit' => 'boite', 'category' => 'epicerie'],
        ['name' => 'Oignon', 'quantity' => 1, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ],
    'Poulet rôti et pommes de terre' => [
        ['name' => 'Poulet', 'quantity' => 1.2, 'unit' => 'kg', 'category' => 'viande_poisson'],
        ['name' => 'Pommes de terre', 'quantity' => 1, 'unit' => 'kg', 'category' => 'fruits_legumes'],
        ['name' => 'Thym'],
    ],
    'Quiche lorraine' => [
        ['name' => 'Pâte brisée', 'quantity' => 1, 'unit' => 'piece', 'category' => 'cremerie'],
        ['name' => 'Lardons', 'quantity' => 200, 'unit' => 'g', 'category' => 'viande_poisson'],
        ['name' => 'Crème fraîche', 'quantity' => 200, 'unit' => 'ml', 'category' => 'cremerie'],
        ['name' => 'Œufs', 'quantity' => 3, 'unit' => 'piece', 'category' => 'cremerie'],
    ],
    'Gratin dauphinois' => [
        ['name' => 'Pommes de terre', 'quantity' => 800, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Crème fraîche', 'quantity' => 0.3, 'unit' => 'l', 'category' => 'cremerie'],
        ['name' => 'Gruyère râpé', 'quantity' => 100, 'unit' => 'g', 'category' => 'cremerie'],
    ],
    'Saumon et riz' => [
        ['name' => 'Pavé de saumon', 'quantity' => 2, 'unit' => 'piece', 'category' => 'viande_poisson'],
        ['name' => 'Riz', 'quantity' => 250, 'unit' => 'g', 'category' => 'epicerie'],
        ['name' => 'Citron', 'quantity' => 1, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ],
    'Soupe de légumes' => [
        ['name' => 'Carottes', 'quantity' => 400, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Poireaux', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes'],
        ['name' => 'Pommes de terre', 'quantity' => 300, 'unit' => 'g', 'category' => 'fruits_legumes'],
    ],
    'Omelette aux champignons' => [
        ['name' => 'Œufs', 'quantity' => 6, 'unit' => 'piece', 'category' => 'cremerie'],
        ['name' => 'Champignons de Paris', 'quantity' => 250, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Beurre', 'quantity' => 20, 'unit' => 'g', 'category' => 'cremerie'],
    ],
    'Curry de pois chiches' => [
        ['name' => 'Pois chiches', 'quantity' => 2, 'unit' => 'boite', 'category' => 'epicerie'],
        ['name' => 'Lait de coco', 'quantity' => 400, 'unit' => 'ml', 'category' => 'epicerie'],
        ['name' => 'Curry', 'quantity' => 2, 'unit' => 'cuillere_a_cafe', 'category' => 'epicerie'],
        ['name' => 'Riz', 'quantity' => 250, 'unit' => 'g', 'category' => 'epicerie'],
    ],
    'Steak haché frites' => [
        ['name' => 'Steak haché', 'quantity' => 2, 'unit' => 'piece', 'category' => 'viande_poisson'],
        ['name' => 'Frites surgelées', 'quantity' => 600, 'unit' => 'g', 'category' => 'surgele'],
    ],
    'Salade César' => [
        ['name' => 'Salade', 'quantity' => 1, 'unit' => 'piece', 'category' => 'fruits_legumes'],
        ['name' => 'Blanc de poulet', 'quantity' => 300, 'unit' => 'g', 'category' => 'viande_poisson'],
        ['name' => 'Parmesan', 'quantity' => 50, 'unit' => 'g', 'category' => 'cremerie'],
        ['name' => 'Pain', 'quantity' => 1, 'unit' => 'piece', 'category' => 'boulangerie'],
    ],
];

foreach ($catalog as $name => $ingredients) {
    try {
        $recipes->create($user['householdId'], ['name' => $name, 'ingredients' => $ingredients]);
        echo "  + {$name}\n";
    } catch (Throwable $e) {
        echo "  = {$name} (déjà présente)\n";
    }
}

echo "\nCompte prêt : {$username}\n";
