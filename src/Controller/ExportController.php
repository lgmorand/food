<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Domain\Backup;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;

final class ExportController
{
    /**
     * Export lisible du catalogue : référentiel d'ingrédients et recettes
     * avec leurs ingrédients.
     */
    public function json(): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::download(
            Backup::build($householdId, false),
            'food-export-' . date('Y-m-d') . '.json'
        );
    }

    /** Sauvegarde complète : catalogue, menus et listes de courses. */
    public function backup(): Response
    {
        $householdId = Auth::requireHouseholdId();

        return Response::download(
            Backup::build($householdId, true),
            'food-sauvegarde-' . date('Y-m-d') . '.json'
        );
    }

    /**
     * Restaure un export ou une sauvegarde. Corps attendu :
     * `{ "mode": "merge|replace", "data": { ... } }`.
     */
    public function import(Request $request): Response
    {
        $householdId = Auth::requireHouseholdId();

        $data = $request->input('data');
        if (!is_array($data)) {
            throw HttpException::badRequest('Aucun fichier de sauvegarde à importer.');
        }

        $mode = $request->string('mode', 'merge');
        $counts = Backup::restore($householdId, $data, $mode);

        return Response::json([
            'mode' => $mode,
            'imported' => $counts,
            'message' => self::summary($mode, $counts),
        ]);
    }

    /** @param array<string, int> $counts */
    private static function summary(string $mode, array $counts): string
    {
        $parts = [
            "{$counts['recipesCreated']} recette(s) importée(s)",
            "{$counts['ingredientsCreated']} ingrédient(s) importé(s)",
        ];
        if ($counts['menusCreated'] > 0) {
            $parts[] = "{$counts['menusCreated']} menu(s) importé(s)";
        }
        $skipped = $counts['recipesSkipped'] + $counts['ingredientsSkipped'] + $counts['menusSkipped'];
        if ($mode === 'merge' && $skipped > 0) {
            $parts[] = "{$skipped} déjà présent(s), ignoré(s)";
        }

        return implode(', ', $parts) . '.';
    }
}
