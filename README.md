# Food

Application web (mobile-first) de menus hebdomadaires et de liste de courses
pour un foyer de deux personnes.

- Catalogue de recettes (nom, photo optionnelle, ingrédients)
- Génération d'un menu de 5 ou 6 recettes sans doublon
- Remplacement / retrait d'une recette avec nouveau tirage au hasard
- Liste de courses agrégée par rayon à partir du menu validé
- Partage des données entre les membres d'un même foyer (invitation)

Les spécifications fonctionnelles sont dans [SPECS.md](SPECS.md).

## Pile technique

| Élément  | Choix                                          |
|----------|------------------------------------------------|
| Backend  | PHP 8.1+ (sans framework ni Composer)          |
| Base     | SQLite (PDO)                                   |
| Frontend | SPA vanilla JS + CSS, sans dépendance          |
| Sessions | Sessions PHP natives, cookie `HttpOnly`        |

Extensions PHP requises : `pdo_sqlite`, `mbstring`, `fileinfo`, et `gd`
(redimensionnement des photos de recettes).

## Installation et lancement

```bash
git clone <ce dépôt> && cd food
php -S localhost:8000 -t public public/index.php
```

La base SQLite et son schéma sont créés automatiquement au premier appel
(`database/food.sqlite`). Ouvrez ensuite <http://localhost:8000> et créez un
compte ; le premier compte crée son foyer.

### Jeu de démonstration

```bash
php bin/seed.php
```

Crée le compte `demo@food.local` / `motdepasse1` avec 10 recettes.

### Variables d'environnement

| Variable           | Défaut                | Rôle                          |
|--------------------|-----------------------|-------------------------------|
| `FOOD_DB_PATH`     | `database/food.sqlite`| Chemin du fichier SQLite      |
| `FOOD_UPLOAD_DIR`  | `public/uploads`      | Dossier des photos de recettes|

## Tests

```bash
php tests/run.php    # 25 tests métier / 63 assertions (base temporaire)
php tests/http.php   # 26 vérifications end-to-end (serveur requis)
```

`tests/http.php` attend un serveur sur `http://127.0.0.1:8321` :

```bash
php -S 127.0.0.1:8321 -t public public/index.php
```

> Le serveur de développement PHP sous Windows réinitialise parfois une
> connexion sur plusieurs dizaines ; la suite HTTP rejoue donc les requêtes
> échouées au niveau transport. Ce comportement n'existe pas derrière
> Apache/Nginx.

## Déploiement

La racine du site doit pointer sur `public/` ; tout ce qui n'est pas un fichier
existant est routé vers `public/index.php` (une configuration Apache est fournie
dans `public/.htaccess`).

Exemple Nginx :

```nginx
root /var/www/food/public;
index index.php;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME $document_root/index.php; }
```

Le dossier `database/` doit être accessible en écriture par PHP, ainsi que
`public/uploads/`. Servez l'application en HTTPS : le cookie de session est
alors émis avec l'attribut `Secure`.

## Organisation du code

```
bootstrap.php          autoloader, constantes, fuseau horaire
bin/seed.php           jeu de démonstration
database/schema.sql    schéma SQLite
public/                racine web : front controller, SPA, uploads
src/Database.php       connexion PDO + migrations
src/Auth.php           comptes, foyers, invitations
src/Http/              requête, réponse, routeur, exceptions
src/Domain/            unités, générateur de menu, agrégation des courses
src/Repository/        accès aux données (recettes, menus, listes)
src/Controller/        endpoints REST
src/routes.php         table de routage
tests/                 suites métier et HTTP
```
