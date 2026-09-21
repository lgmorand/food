<?php

declare(strict_types=1);

namespace Food;

use Food\Http\HttpException;
use Food\Http\Request;
use PDO;

final class Auth
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $https,
                // Limité au sous-dossier de publication pour ne pas entrer en
                // conflit avec une autre application du même domaine.
                'path' => Request::detectBasePath() . '/',
            ]);
            session_start();
        }
    }

    /** Identifiant du compte créé au premier lancement. */
    public const DEFAULT_USERNAME = 'morand';

    /** Longueur minimale d'un mot de passe. */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Vrai tant qu'aucun compte n'existe : l'application affiche alors
     * l'écran de première utilisation.
     */
    public static function needsSetup(): bool
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    }

    /**
     * Crée l'unique compte de l'application, puis ouvre la session.
     */
    public static function setup(string $password, string $username = self::DEFAULT_USERNAME, string $displayName = ''): array
    {
        if (!self::needsSetup()) {
            throw HttpException::conflict('Le compte est déjà créé.');
        }

        $username = self::normalizeUsername($username);
        if ($username === '') {
            throw HttpException::badRequest('Identifiant invalide.', ['username' => 'Identifiant requis.']);
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw HttpException::badRequest(
                'Le mot de passe doit faire au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.',
                ['password' => 'Minimum ' . self::MIN_PASSWORD_LENGTH . ' caractères.']
            );
        }

        $displayName = trim($displayName) !== '' ? trim($displayName) : $username;
        $now = Support::now();
        $pdo = Database::connection();

        $householdId = Support::uuid();
        $pdo->prepare('INSERT INTO households (id, name, created_at) VALUES (?, ?, ?)')
            ->execute([$householdId, 'Foyer ' . $displayName, $now]);

        $userId = Support::uuid();
        $pdo->prepare('INSERT INTO users (id, household_id, username, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $householdId, $username, password_hash($password, PASSWORD_DEFAULT), $displayName, $now]);

        $user = self::findUser($userId);
        self::login($user);

        return $user;
    }

    public static function attempt(string $username, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([self::normalizeUsername($username)]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password_hash'])) {
            throw HttpException::unauthorized('Identifiant ou mot de passe incorrect.');
        }

        $user = self::publicUser($row);
        self::login($user);

        return $user;
    }

    public static function changePassword(string $userId, string $currentPassword, string $newPassword): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($currentPassword, $row['password_hash'])) {
            throw HttpException::badRequest(
                'Mot de passe actuel incorrect.',
                ['currentPassword' => 'Mot de passe actuel incorrect.']
            );
        }
        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw HttpException::badRequest(
                'Le mot de passe doit faire au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.',
                ['newPassword' => 'Minimum ' . self::MIN_PASSWORD_LENGTH . ' caractères.']
            );
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    public static function login(array $user): void
    {
        self::start();
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
        } else {
            $_SESSION['user_id'] = $user['id'];
        }
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function user(): ?array
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!is_string($id)) {
            return null;
        }

        return self::findUser($id);
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    public static function requireHouseholdId(): string
    {
        return self::requireUser()['householdId'];
    }

    public static function changeUsername(string $userId, string $currentPassword, string $newUsername): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($currentPassword, $row['password_hash'])) {
            throw HttpException::badRequest(
                'Mot de passe incorrect.',
                ['currentPassword' => 'Mot de passe incorrect.']
            );
        }

        $newUsername = self::normalizeUsername($newUsername);
        if ($newUsername === '') {
            throw HttpException::badRequest('Identifiant invalide.', ['username' => 'Identifiant requis.']);
        }

        $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
        $taken->execute([$newUsername, $userId]);
        if ($taken->fetchColumn() !== false) {
            throw HttpException::conflict('Cet identifiant est déjà utilisé.');
        }

        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$newUsername, $userId]);

        return self::findUser($userId) ?? throw HttpException::notFound();
    }

    public static function createHousehold(string $name): string
    {
        $householdId = Support::uuid();
        Database::connection()
            ->prepare('INSERT INTO households (id, name, created_at) VALUES (?, ?, ?)')
            ->execute([$householdId, $name, Support::now()]);

        return $householdId;
    }

    private static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    private static function findUser(string $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::publicUser($row);
    }

    private static function publicUser(array $row): array
    {
        return [
            'id' => $row['id'],
            'username' => $row['username'],
            'displayName' => $row['display_name'],
            'householdId' => $row['household_id'],
        ];
    }
}
