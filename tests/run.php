<?php

declare(strict_types=1);

/**
 * Suite de tests de l'application Food.
 * Exécution : php tests/run.php
 */

$dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'food-tests-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('FOOD_DB_PATH=' . $dbPath);

require __DIR__ . '/../bootstrap.php';

use Food\Auth;
use Food\Database;
use Food\Domain\MenuGenerator;
use Food\Domain\Units;
use Food\Http\HttpException;
use Food\Repository\IngredientRepository;
use Food\Repository\MenuRepository;
use Food\Repository\RecipeRepository;
use Food\Repository\ShoppingListRepository;

final class TestRunner
{
    private int $passed = 0;
    private array $failures = [];
    private string $current = '';

    public function test(string $name, callable $fn): void
    {
        $this->current = $name;
        try {
            $fn($this);
            echo "  OK   {$name}\n";
        } catch (Throwable $e) {
            $this->failures[] = "{$name} : " . $e->getMessage();
            echo "  FAIL {$name} -> " . $e->getMessage() . "\n";
        }
    }

    public function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        $this->passed++;
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert(
            $expected === $actual,
            $message . ' (attendu: ' . var_export($expected, true) . ', obtenu: ' . var_export($actual, true) . ')'
        );
    }

    public function assertThrows(string $expectedMessagePart, callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assert(
                str_contains(mb_strtolower($e->getMessage()), mb_strtolower($expectedMessagePart)),
                $message . " (message obtenu: {$e->getMessage()})"
            );

            return;
        }
        throw new RuntimeException($message . ' (aucune exception levée)');
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('-', 60) . "\n";
        if ($this->failures === []) {
            echo "Tous les tests passent ({$this->passed} assertions).\n";

            return 0;
        }
        echo count($this->failures) . " test(s) en échec :\n";
        foreach ($this->failures as $failure) {
            echo "  - {$failure}\n";
        }

        return 1;
    }
}

$t = new TestRunner();
$ingredients = new IngredientRepository();
$recipes = new RecipeRepository();
$menus = new MenuRepository();
$lists = new ShoppingListRepository();

$user = Auth::setup('motdepasse1', 'testeur');
$household = $user['householdId'];

function makeRecipe(RecipeRepository $recipes, string $household, string $name, array $ingredients): array
{
    return $recipes->create($household, ['name' => $name, 'ingredients' => $ingredients]);
}

echo "Tests Food\n" . str_repeat('-', 60) . "\n";

$t->test('Normalisation des clés de doublon', function (TestRunner $t): void {
    $t->assertSame('tomate', \Food\Support::normalizeKey('Tomates'), 'pluriel et accents normalisés');
    $t->assertSame('creme fraiche', \Food\Support::normalizeKey('Crème fraîche'), 'accents normalisés');
    $t->assertSame('oeuf', \Food\Support::normalizeKey('Œufs'), 'ligature normalisée');
});

$t->test('Création de recettes et d\'ingrédients à la volée', function (TestRunner $t) use ($recipes, $ingredients, $household): void {
    $recipe = makeRecipe($recipes, $household, 'Pâtes bolognaise', [
        ['name' => 'Pâtes', 'quantity' => 500, 'unit' => 'g', 'category' => 'epicerie'],
        ['name' => 'Bœuf haché', 'quantity' => 400, 'unit' => 'g', 'category' => 'viande_poisson'],
        ['name' => 'Tomates', 'quantity' => 4, 'unit' => 'piece', 'category' => 'fruits_legumes'],
        ['name' => 'Sel'],
    ]);

    $t->assertSame(4, $recipe['ingredientCount'], '4 ingrédients enregistrés');
    $t->assert($recipe['isActive'], 'la recette est active par défaut');
    $t->assertSame(null, $recipe['ingredients'][3]['quantity'], 'le sel n\'a pas de quantité');
    $t->assert(count($ingredients->all($household)) === 4, 'les ingrédients sont créés dans le référentiel');
});

$t->test('Nom de recette unique par foyer', function (TestRunner $t) use ($recipes, $household): void {
    $t->assertThrows(
        'existe déjà',
        static fn () => makeRecipe($recipes, $household, 'pates bolognaises', []),
        'un doublon de nom est refusé'
    );
});

$t->test('Un ingrédient ne peut pas figurer deux fois dans une recette', function (TestRunner $t) use ($recipes, $household): void {
    $t->assertThrows(
        'deux fois',
        static fn () => makeRecipe($recipes, $household, 'Doublon', [
            ['name' => 'Tomates', 'quantity' => 1, 'unit' => 'piece'],
            ['name' => 'tomate', 'quantity' => 2, 'unit' => 'piece'],
        ]),
        'le doublon d\'ingrédient est refusé'
    );
});

// Catalogue de 8 recettes pour tester la génération.
$catalog = [
    'Poulet rôti' => [
        ['name' => 'Poulet', 'quantity' => 1.2, 'unit' => 'kg', 'category' => 'viande_poisson'],
        ['name' => 'Pommes de terre', 'quantity' => 1, 'unit' => 'kg', 'category' => 'fruits_legumes'],
    ],
    'Quiche lorraine' => [
        ['name' => 'Lardons', 'quantity' => 200, 'unit' => 'g', 'category' => 'viande_poisson'],
        ['name' => 'Crème fraîche', 'quantity' => 200, 'unit' => 'ml', 'category' => 'cremerie'],
        ['name' => 'Œufs', 'quantity' => 3, 'unit' => 'piece', 'category' => 'cremerie'],
    ],
    'Salade de tomates' => [
        ['name' => 'Tomates', 'quantity' => 500, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Huile d\'olive', 'quantity' => 2, 'unit' => 'cuillere_a_soupe', 'category' => 'epicerie'],
    ],
    'Gratin dauphinois' => [
        ['name' => 'Pommes de terre', 'quantity' => 800, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Crème fraîche', 'quantity' => 0.3, 'unit' => 'l', 'category' => 'cremerie'],
    ],
    'Omelette' => [
        ['name' => 'Œufs', 'quantity' => 6, 'unit' => 'piece', 'category' => 'cremerie'],
        ['name' => 'Sel'],
    ],
    'Soupe de légumes' => [
        ['name' => 'Carottes', 'quantity' => 400, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Poireaux', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ],
    'Riz cantonais' => [
        ['name' => 'Riz', 'quantity' => 300, 'unit' => 'g', 'category' => 'epicerie'],
        ['name' => 'Œufs', 'quantity' => 2, 'unit' => 'piece', 'category' => 'cremerie'],
    ],
];
foreach ($catalog as $name => $lines) {
    makeRecipe($recipes, $household, $name, $lines);
}

$t->test('Génération d\'un menu de 5 recettes distinctes', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 5);
    $t->assertSame(5, count($menu['items']), '5 cartes générées');
    $t->assertSame(5, count(array_unique($menu['recipeIds'])), 'aucune recette en double');
    $t->assertSame('draft', $menu['status'], 'le menu est un brouillon');
});

$t->test('Génération d\'un menu de 6 recettes distinctes', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 6);
    $t->assertSame(6, count($menu['items']), '6 cartes générées');
    $t->assertSame(6, count(array_unique($menu['recipeIds'])), 'aucune recette en double');
});

$t->test('Une taille différente de 5 ou 6 est refusée', function (TestRunner $t) use ($menus, $household): void {
    $t->assertThrows('5 ou 6', static fn () => $menus->generate($household, 4), 'taille 4 refusée');
    $t->assertThrows('5 ou 6', static fn () => $menus->generate($household, 7), 'taille 7 refusée');
});

$t->test('Le remplacement tire une recette absente du menu', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 5);
    $before = $menu['items'][2]['recipeId'];
    $after = $menus->replaceItem($household, $menu['id'], 2);

    $t->assert($after['items'][2]['recipeId'] !== $before, 'la recette de la position 2 a changé');
    $t->assertSame(5, count(array_unique($after['recipeIds'])), 'toujours aucun doublon');
    $t->assert(!in_array($before, array_slice($after['recipeIds'], 2, 1), true), 'l\'ancienne recette est partie');
});

$t->test('La suppression d\'une carte la remplace par un tirage aléatoire', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 5);
    $before = $menu['items'][0]['recipeId'];
    $after = $menus->removeItem($household, $menu['id'], 0);

    $t->assertSame(5, count($after['items']), 'le menu compte toujours 5 cartes');
    $t->assert($after['items'][0]['recipeId'] !== $before, 'une nouvelle recette a été tirée');
    $t->assertSame(5, count(array_unique($after['recipeIds'])), 'aucun doublon après remplacement');
});

$t->test('Les cartes verrouillées survivent à une régénération complète', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 5);
    $menu = $menus->toggleLock($household, $menu['id'], 1, true);
    $locked = $menu['items'][1]['recipeId'];

    $regenerated = $menus->regenerate($household, $menu['id']);
    $t->assertSame($locked, $regenerated['items'][1]['recipeId'], 'la carte verrouillée est conservée');
    $t->assertSame(5, count(array_unique($regenerated['recipeIds'])), 'aucun doublon après régénération');
});

$t->test('Affectation manuelle d\'une recette déjà présente refusée', function (TestRunner $t) use ($menus, $household): void {
    $menu = $menus->generate($household, 5);
    $duplicate = $menu['items'][0]['recipeId'];

    $t->assertThrows(
        'déjà dans le menu',
        static fn () => $menus->assignItem($household, $menu['id'], 3, $duplicate),
        'le doublon manuel est refusé'
    );
});

$t->test('Menu incomplet quand le catalogue est trop petit', function (TestRunner $t) use ($recipes, $menus): void {
    $small = Auth::createHousehold('Petit');
    makeRecipe($recipes, $small, 'Unique', [['name' => 'Pain', 'quantity' => 1, 'unit' => 'piece']]);

    $menu = $menus->generate($small, 5);
    $t->assertSame(1, count($menu['items']), 'une seule carte remplie');
    $t->assertThrows(
        'aucune autre recette',
        static fn () => $menus->replaceItem($small, $menu['id'], 0),
        'le remplacement impossible est signalé'
    );
});

$t->test('Agrégation des quantités par unité compatible', function (TestRunner $t) use ($recipes, $menus, $lists): void {
    $h = Auth::createHousehold('Courses');

    makeRecipe($recipes, $h, 'Recette A', [
        ['name' => 'Farine', 'quantity' => 800, 'unit' => 'g', 'category' => 'epicerie'],
        ['name' => 'Lait', 'quantity' => 500, 'unit' => 'ml', 'category' => 'cremerie'],
        ['name' => 'Tomates', 'quantity' => 500, 'unit' => 'g', 'category' => 'fruits_legumes'],
        ['name' => 'Sel'],
    ]);
    makeRecipe($recipes, $h, 'Recette B', [
        ['name' => 'Farine', 'quantity' => 0.5, 'unit' => 'kg', 'category' => 'epicerie'],
        ['name' => 'Lait', 'quantity' => 1, 'unit' => 'l', 'category' => 'cremerie'],
        ['name' => 'Tomates', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ]);
    for ($i = 1; $i <= 3; $i++) {
        makeRecipe($recipes, $h, "Remplissage {$i}", [['name' => "Ingrédient {$i}", 'quantity' => 1, 'unit' => 'piece']]);
    }

    $menu = $menus->generate($h, 5);
    $menu = $menus->validate($h, $menu['id']);
    $list = $lists->generateForMenu($h, $menu['id']);

    $items = [];
    foreach ($list['groups'] as $group) {
        foreach ($group['items'] as $item) {
            $items[$item['label']] = $item;
        }
    }

    $t->assertSame('1,3 kg', $items['Farine']['display'], '800 g + 0,5 kg = 1,3 kg');
    $t->assertSame('1,5 L', $items['Lait']['display'], '500 ml + 1 L = 1,5 L');
    $t->assertSame('500 g + 2 pièce(s)', $items['Tomates']['display'], 'unités incompatibles affichées séparément');
    $t->assertSame('', $items['Sel']['display'], 'ingrédient sans quantité affiché sans quantité');
    $t->assertSame(2, count($items['Farine']['sourceRecipes']), 'les deux recettes sources sont listées');
});

$t->test('Regroupement de la liste par rayon', function (TestRunner $t) use ($recipes, $menus, $lists): void {
    $h = Auth::createHousehold('Rayons');
    makeRecipe($recipes, $h, 'Rayon test', [
        ['name' => 'Pain', 'quantity' => 1, 'unit' => 'piece', 'category' => 'boulangerie'],
        ['name' => 'Steak', 'quantity' => 2, 'unit' => 'piece', 'category' => 'viande_poisson'],
        ['name' => 'Salade', 'quantity' => 1, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ]);

    $menu = $menus->generate($h, 5);
    $menu = $menus->validate($h, $menu['id']);
    $list = $lists->generateForMenu($h, $menu['id']);

    $categories = array_column($list['groups'], 'category');
    $t->assertSame(['fruits_legumes', 'viande_poisson', 'boulangerie'], $categories, 'rayons dans l\'ordre du parcours magasin');
});

$t->test('Cocher un article et ajouter un article libre', function (TestRunner $t) use ($recipes, $menus, $lists): void {
    $h = Auth::createHousehold('Caddie');
    makeRecipe($recipes, $h, 'Simple', [['name' => 'Beurre', 'quantity' => 250, 'unit' => 'g', 'category' => 'cremerie']]);

    $menu = $menus->validate($h, $menus->generate($h, 5)['id']);
    $list = $lists->generateForMenu($h, $menu['id']);
    $itemId = $list['groups'][0]['items'][0]['id'];

    $list = $lists->toggleItem($h, $list['id'], $itemId, true);
    $t->assertSame(1, $list['checkedCount'], 'article coché');

    $list = $lists->addManualItem($h, $list['id'], 'Sacs poubelle', 'entretien');
    $t->assertSame(2, $list['totalCount'], 'article libre ajouté');

    $list = $lists->uncheckAll($h, $list['id']);
    $t->assertSame(0, $list['checkedCount'], 'tout décoché');
});

$t->test('Un menu validé ne peut plus être modifié', function (TestRunner $t) use ($recipes, $menus): void {
    $h = Auth::createHousehold('Figé');
    for ($i = 1; $i <= 6; $i++) {
        makeRecipe($recipes, $h, "Plat {$i}", [['name' => "Produit {$i}", 'quantity' => 1, 'unit' => 'piece']]);
    }

    $menu = $menus->validate($h, $menus->generate($h, 5)['id']);
    $t->assertSame('validated', $menu['status'], 'le menu est validé');
    $t->assert($menu['validatedAt'] !== null, 'la date de validation est renseignée');
    $t->assertThrows(
        'déjà validé',
        static fn () => $menus->replaceItem($h, $menu['id'], 0),
        'modification refusée après validation'
    );
});

$t->test('Modifier une recette ne modifie pas une liste déjà générée', function (TestRunner $t) use ($recipes, $menus, $lists): void {
    $h = Auth::createHousehold('Figé2');
    $recipe = makeRecipe($recipes, $h, 'Plat figé', [
        ['name' => 'Courgette', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ]);

    $menu = $menus->validate($h, $menus->generate($h, 5)['id']);
    $list = $lists->generateForMenu($h, $menu['id']);
    $before = $list['totalCount'];

    $recipes->update($h, $recipe['id'], ['ingredients' => [
        ['name' => 'Courgette', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes'],
        ['name' => 'Aubergine', 'quantity' => 1, 'unit' => 'piece', 'category' => 'fruits_legumes'],
    ]]);

    $after = $lists->find($h, $list['id']);
    $t->assertSame($before, $after['totalCount'], 'la liste générée est figée');
});

$t->test('Anti-répétition sur les 2 dernières semaines validées', function (TestRunner $t) use ($recipes, $menus): void {
    $h = Auth::createHousehold('Répétition');
    for ($i = 1; $i <= 10; $i++) {
        makeRecipe($recipes, $h, "Semaine plat {$i}", [['name' => "Produit R{$i}", 'quantity' => 1, 'unit' => 'piece']]);
    }

    $week1 = $menus->generate($h, 5, '2026-01-05');
    $validated = $menus->validate($h, $week1['id']);
    $used = $validated['recipeIds'];

    $week2 = $menus->generate($h, 5, '2026-01-12');
    $overlap = array_intersect($used, $week2['recipeIds']);
    $t->assertSame(0, count($overlap), 'les recettes de la semaine précédente sont évitées');

    $generator = new MenuGenerator();
    $recent = $generator->recentlyUsedRecipeIds($h);
    $t->assertSame(5, count($recent), 'les 5 recettes validées sont marquées comme récentes');
});

$t->test('Une recette inactive n\'est jamais tirée', function (TestRunner $t) use ($recipes, $menus): void {
    $h = Auth::createHousehold('Inactive');
    for ($i = 1; $i <= 6; $i++) {
        makeRecipe($recipes, $h, "Actif {$i}", [['name' => "Produit I{$i}", 'quantity' => 1, 'unit' => 'piece']]);
    }
    $hidden = makeRecipe($recipes, $h, 'Cachée', [['name' => 'Produit caché', 'quantity' => 1, 'unit' => 'piece']]);
    $recipes->update($h, $hidden['id'], ['isActive' => false]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $menu = $menus->generate($h, 6);
        $t->assert(!in_array($hidden['id'], $menu['recipeIds'], true), 'la recette inactive est exclue');
    }
});

$t->test('Suppression d\'un ingrédient utilisé refusée', function (TestRunner $t) use ($recipes, $ingredients): void {
    $h = Auth::createHousehold('Ingrédient');
    makeRecipe($recipes, $h, 'Avec pomme', [['name' => 'Pomme', 'quantity' => 3, 'unit' => 'piece']]);
    $pomme = $ingredients->findByName($h, 'pommes');

    $t->assert($pomme !== null, 'l\'ingrédient est retrouvé malgré le pluriel');
    $t->assertThrows(
        'utilisé par au moins une recette',
        static fn () => $ingredients->delete($h, $pomme['id']),
        'la suppression est bloquée'
    );
});

$t->test('Cloisonnement des données entre foyers', function (TestRunner $t) use ($recipes): void {
    $a = Auth::createHousehold('A');
    $b = Auth::createHousehold('B');
    $recipe = makeRecipe($recipes, $a, 'Privée', []);

    $t->assertSame(null, $recipes->find($b, $recipe['id']), 'le foyer B ne voit pas la recette du foyer A');
    $t->assertSame(0, count($recipes->all($b)), 'le catalogue du foyer B est vide');
});

$t->test('Compte unique partagé', function (TestRunner $t) use ($user): void {
    $t->assertSame('testeur', $user['username'], "l'identifiant est enregistré en minuscules");
    $t->assertSame(false, Auth::needsSetup(), 'le compte existe, plus besoin de première configuration');
    $t->assertThrows(
        'déjà créé',
        static fn () => Auth::setup('motdepasse2', 'autre'),
        'un second compte ne peut pas être créé'
    );
    $t->assertSame($user['id'], Auth::attempt('TESTEUR', 'motdepasse1')['id'], "l'identifiant est insensible à la casse");
    $t->assertThrows(
        'incorrect',
        static fn () => Auth::attempt('testeur', 'mauvais'),
        'un mot de passe erroné est rejeté'
    );
});

$t->test('Changement de mot de passe', function (TestRunner $t) use ($user): void {
    $t->assertThrows(
        'actuel incorrect',
        static fn () => Auth::changePassword($user['id'], 'mauvais', 'nouveaumotdepasse'),
        "l'ancien mot de passe est vérifié"
    );
    $t->assertThrows(
        '8 caractères',
        static fn () => Auth::changePassword($user['id'], 'motdepasse1', 'court'),
        'un mot de passe trop court est refusé'
    );

    Auth::changePassword($user['id'], 'motdepasse1', 'motdepasse2');
    $t->assertSame($user['id'], Auth::attempt('testeur', 'motdepasse2')['id'], 'le nouveau mot de passe fonctionne');
    Auth::changePassword($user['id'], 'motdepasse2', 'motdepasse1');
});

$t->test('Rejouer un menu depuis l\'historique', function (TestRunner $t) use ($recipes, $menus): void {
    $h = Auth::createHousehold('Rejouer');
    for ($i = 1; $i <= 8; $i++) {
        makeRecipe($recipes, $h, "Histo {$i}", [['name' => "Produit H{$i}", 'quantity' => 1, 'unit' => 'piece']]);
    }

    $validated = $menus->validate($h, $menus->generate($h, 5, '2026-02-02')['id']);
    $replayed = $menus->replay($h, $validated['id'], '2026-03-02');

    $t->assertSame('draft', $replayed['status'], 'le menu rejoué est un brouillon');
    $t->assertSame($validated['recipeIds'], $replayed['recipeIds'], 'mêmes recettes reprises');
    $t->assertSame(1, count($menus->history($h)), "l'historique contient le menu validé");
});

$t->test('Conversions et formatage des unités', function (TestRunner $t): void {
    $t->assertSame(1200.0, Units::toBase(1.2, 'kg'), '1,2 kg = 1200 g');
    $t->assertSame(['quantity' => 1.2, 'unit' => 'kg'], Units::humanize(1200, 'masse'), '1200 g -> 1,2 kg');
    $t->assertSame(['quantity' => 750.0, 'unit' => 'ml'], Units::humanize(750, 'volume'), '750 ml reste en ml');
    $t->assertSame('2 c. à soupe', Units::format(2, 'cuillere_a_soupe'), 'libellé lisible');
    $t->assertSame('autre', Units::normalizeCategory('inconnu'), 'rayon inconnu -> autre');
});

$t->test('Semaine calendaire calée sur le lundi', function (TestRunner $t): void {
    $t->assertSame('2026-09-14', \Food\Support::mondayOf('2026-09-20'), 'dimanche -> lundi précédent');
    $t->assertSame('2026-09-14', \Food\Support::mondayOf('2026-09-14'), 'lundi inchangé');
});

$t->test('Publication dans un sous-dossier', function (TestRunner $t): void {
    $request = static function (string $script, string $uri): \Food\Http\Request {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        return \Food\Http\Request::fromGlobals();
    };

    $racine = $request('/index.php', '/api/menus/current');
    $t->assertSame('', $racine->basePath, 'racine du domaine : aucun préfixe');
    $t->assertSame('/api/menus/current', $racine->path, 'chemin inchangé à la racine');

    $sous = $request('/food/index.php', '/food/api/menus/current?x=1');
    $t->assertSame('/food', $sous->basePath, 'préfixe détecté');
    $t->assertSame('/api/menus/current', $sous->path, 'préfixe retiré du chemin');

    $accueil = $request('/food/index.php', '/food/');
    $t->assertSame('/', $accueil->path, 'accueil du sous-dossier');

    $sansSlash = $request('/food/index.php', '/food');
    $t->assertSame('/', $sansSlash->path, 'accueil sans barre oblique finale');

    $homonyme = $request('/food/index.php', '/foodie/api/menus/current');
    $t->assertSame('/foodie/api/menus/current', $homonyme->path, 'préfixe homonyme non retiré');

    $statique = $request('/assets/app.js', '/assets/app.js');
    $t->assertSame('', $statique->basePath, 'un fichier statique ne définit pas de préfixe');
    $t->assertSame('/assets/app.js', $statique->path, 'chemin du fichier statique inchangé');

    unset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
});

$exit = $t->summary();

Database::reset();
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');

exit($exit);
