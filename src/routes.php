<?php

declare(strict_types=1);

use Food\Controller\AuthController;
use Food\Controller\ExportController;
use Food\Controller\IngredientController;
use Food\Controller\MenuController;
use Food\Controller\RecipeController;
use Food\Controller\ShoppingListController;
use Food\Controller\UploadController;
use Food\Http\Router;

/** @var Router $router */
$router = new Router();

$auth = new AuthController();
$recipes = new RecipeController();
$ingredients = new IngredientController();
$menus = new MenuController();
$lists = new ShoppingListController();
$uploads = new UploadController();
$export = new ExportController();

// Authentification
$router->post('/api/auth/setup', static fn ($r) => $auth->setup($r));
$router->get('/api/auth/status', static fn () => $auth->status());
$router->post('/api/auth/login', static fn ($r) => $auth->login($r));
$router->post('/api/auth/logout', static fn () => $auth->logout());
$router->get('/api/auth/me', static fn () => $auth->me());
$router->post('/api/auth/password', static fn ($r) => $auth->changePassword($r));
$router->post('/api/auth/username', static fn ($r) => $auth->changeUsername($r));

// Export du catalogue
$router->get('/api/export', static fn () => $export->json());

// Recettes
$router->get('/api/recipes', static fn ($r) => $recipes->index($r));
$router->post('/api/recipes', static fn ($r) => $recipes->store($r));
$router->get('/api/recipes/{id}', static fn ($r, $a) => $recipes->show($r, $a));
$router->put('/api/recipes/{id}', static fn ($r, $a) => $recipes->update($r, $a));
$router->delete('/api/recipes/{id}', static fn ($r, $a) => $recipes->destroy($r, $a));

// Ingrédients
$router->get('/api/ingredients', static fn () => $ingredients->index());
$router->post('/api/ingredients', static fn ($r) => $ingredients->store($r));
$router->put('/api/ingredients/{id}', static fn ($r, $a) => $ingredients->update($r, $a));
$router->delete('/api/ingredients/{id}', static fn ($r, $a) => $ingredients->destroy($r, $a));

// Menus
$router->get('/api/menus/current', static fn ($r) => $menus->current($r));
$router->get('/api/menus/history', static fn () => $menus->history());
$router->post('/api/menus/generate', static fn ($r) => $menus->generate($r));
$router->get('/api/menus/{id}', static fn ($r, $a) => $menus->show($r, $a));
$router->post('/api/menus/{id}/regenerate', static fn ($r, $a) => $menus->regenerate($r, $a));
$router->post('/api/menus/{id}/validate', static fn ($r, $a) => $menus->validateMenu($r, $a));
$router->post('/api/menus/{id}/replay', static fn ($r, $a) => $menus->replay($r, $a));
$router->delete('/api/menus/{id}', static fn ($r, $a) => $menus->destroy($r, $a));
$router->post('/api/menus/{id}/items/{position}/replace', static fn ($r, $a) => $menus->replaceItem($r, $a));
$router->post('/api/menus/{id}/items/{position}/lock', static fn ($r, $a) => $menus->lockItem($r, $a));
$router->put('/api/menus/{id}/items/{position}', static fn ($r, $a) => $menus->assignItem($r, $a));
$router->delete('/api/menus/{id}/items/{position}', static fn ($r, $a) => $menus->removeItem($r, $a));

// Liste de courses
$router->get('/api/shopping-list/current', static fn () => $lists->current());
$router->get('/api/shopping-lists/by-menu/{menuId}', static fn ($r, $a) => $lists->forMenu($r, $a));
$router->get('/api/shopping-lists/{id}', static fn ($r, $a) => $lists->show($r, $a));
$router->get('/api/shopping-lists/{id}/export', static fn ($r, $a) => $lists->export($r, $a));
$router->post('/api/shopping-lists/{id}/items', static fn ($r, $a) => $lists->addItem($r, $a));
$router->post('/api/shopping-lists/{id}/uncheck-all', static fn ($r, $a) => $lists->uncheckAll($r, $a));
$router->patch('/api/shopping-lists/{id}/items/{itemId}', static fn ($r, $a) => $lists->toggleItem($r, $a));
$router->delete('/api/shopping-lists/{id}/items/{itemId}', static fn ($r, $a) => $lists->deleteItem($r, $a));

// Upload photo
$router->post('/api/uploads', static fn ($r) => $uploads->store($r));

return $router;
