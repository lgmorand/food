<?php

/**
 * Point d'entrée de secours pour les hébergements où la racine du site ne peut
 * pas pointer sur « public/ » (par exemple un dossier www/food servi sous
 * https://exemple.fr/food). Le .htaccess voisin redirige les requêtes ici et
 * sert les ressources statiques depuis public/.
 *
 * Quand vous pouvez configurer la racine du site, faites-la pointer sur
 * « public/ » : ce fichier devient alors inutile.
 */

declare(strict_types=1);

require __DIR__ . '/public/index.php';
