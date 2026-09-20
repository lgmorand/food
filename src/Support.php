<?php

declare(strict_types=1);

namespace Food;

use Random\RandomException;

final class Support
{
    public static function uuid(): string
    {
        try {
            $data = random_bytes(16);
        } catch (RandomException) {
            $data = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }

    /**
     * Clé de normalisation utilisée pour détecter les doublons :
     * minuscules, sans accents, sans ponctuation, pluriels simples rapprochés.
     */
    public static function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        $words = $value === '' ? [] : explode(' ', $value);
        foreach ($words as $i => $word) {
            if (mb_strlen($word) > 3 && str_ends_with($word, 'x')) {
                $word = substr($word, 0, -1);
            }
            if (mb_strlen($word) > 3 && str_ends_with($word, 's')) {
                $word = substr($word, 0, -1);
            }
            $words[$i] = $word;
        }

        return implode(' ', $words);
    }

    public static function mondayOf(?string $date = null): string
    {
        $d = new \DateTimeImmutable($date ?? 'now');
        $dow = (int) $d->format('N');

        return $d->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }

    public static function jsonDecodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
