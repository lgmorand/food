<?php

declare(strict_types=1);

namespace Food\Domain;

final class Units
{
    public const UNITS = [
        'g' => ['label' => 'g', 'family' => 'masse', 'factor' => 1.0],
        'kg' => ['label' => 'kg', 'family' => 'masse', 'factor' => 1000.0],
        'ml' => ['label' => 'ml', 'family' => 'volume', 'factor' => 1.0],
        'l' => ['label' => 'L', 'family' => 'volume', 'factor' => 1000.0],
        'piece' => ['label' => 'pièce(s)', 'family' => 'piece', 'factor' => 1.0],
        'cuillere_a_soupe' => ['label' => 'c. à soupe', 'family' => 'cuillere_a_soupe', 'factor' => 1.0],
        'cuillere_a_cafe' => ['label' => 'c. à café', 'family' => 'cuillere_a_cafe', 'factor' => 1.0],
        'pincee' => ['label' => 'pincée(s)', 'family' => 'pincee', 'factor' => 1.0],
        'sachet' => ['label' => 'sachet(s)', 'family' => 'sachet', 'factor' => 1.0],
        'boite' => ['label' => 'boîte(s)', 'family' => 'boite', 'factor' => 1.0],
    ];

    public const CATEGORIES = [
        'fruits_legumes' => 'Fruits & légumes',
        'viande_poisson' => 'Viande & poisson',
        'cremerie' => 'Crémerie',
        'epicerie' => 'Épicerie',
        'surgele' => 'Surgelés',
        'boulangerie' => 'Boulangerie',
        'boisson' => 'Boissons',
        'entretien' => 'Entretien',
        'autre' => 'Autre',
    ];

    /** Ordre d'affichage des rayons dans la liste de courses. */
    public const CATEGORY_ORDER = [
        'fruits_legumes', 'viande_poisson', 'cremerie', 'boulangerie',
        'epicerie', 'surgele', 'boisson', 'entretien', 'autre',
    ];

    public static function isValid(?string $unit): bool
    {
        return $unit !== null && isset(self::UNITS[$unit]);
    }

    public static function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null || $unit === '') {
            return null;
        }
        $unit = mb_strtolower(trim($unit));

        return isset(self::UNITS[$unit]) ? $unit : null;
    }

    public static function normalizeCategory(?string $category): string
    {
        $category = $category !== null ? mb_strtolower(trim($category)) : '';

        return isset(self::CATEGORIES[$category]) ? $category : 'autre';
    }

    public static function family(string $unit): string
    {
        return self::UNITS[$unit]['family'] ?? $unit;
    }

    public static function label(string $unit): string
    {
        return self::UNITS[$unit]['label'] ?? $unit;
    }

    public static function toBase(float $quantity, string $unit): float
    {
        return $quantity * (self::UNITS[$unit]['factor'] ?? 1.0);
    }

    /**
     * Convertit une quantité exprimée en unité de base vers l'unité la plus
     * lisible de sa famille (ex. 1200 g -> 1.2 kg).
     *
     * @return array{quantity: float, unit: string}
     */
    public static function humanize(float $baseQuantity, string $family): array
    {
        if ($family === 'masse') {
            return $baseQuantity >= 1000
                ? ['quantity' => self::round($baseQuantity / 1000), 'unit' => 'kg']
                : ['quantity' => self::round($baseQuantity), 'unit' => 'g'];
        }
        if ($family === 'volume') {
            return $baseQuantity >= 1000
                ? ['quantity' => self::round($baseQuantity / 1000), 'unit' => 'l']
                : ['quantity' => self::round($baseQuantity), 'unit' => 'ml'];
        }

        return ['quantity' => self::round($baseQuantity), 'unit' => $family];
    }

    public static function round(float $value): float
    {
        return round($value, 2);
    }

    public static function format(float $quantity, string $unit): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 2, ',', ' '), '0'), ',');

        return $formatted . ' ' . self::label($unit);
    }
}
