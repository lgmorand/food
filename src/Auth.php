<?php

declare(strict_types=1);

namespace Food;

use Food\Http\HttpException;
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
                'path' => '/',
            ]);
            session_start();
        }
    }

    public static function register(string $email, string $password, string $displayName, ?string $invitationToken = null): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::badRequest('Adresse e-mail invalide.', ['email' => 'Adresse e-mail invalide.']);
        }
        if (mb_strlen($password) < 8) {
            throw HttpException::badRequest('Le mot de passe doit faire au moins 8 caractères.', ['password' => 'Minimum 8 caractères.']);
        }
        $displayName = trim($displayName) !== '' ? trim($displayName) : ((string) strstr($email, '@', true) ?: $email);

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetchColumn() !== false) {
            throw HttpException::conflict('Un compte existe déjà avec cette adresse.');
        }

        $householdId = null;
        if ($invitationToken !== null && $invitationToken !== '') {
            $householdId = self::consumeInvitation($invitationToken);
        }

        $now = Support::now();
        if ($householdId === null) {
            $householdId = Support::uuid();
            $pdo->prepare('INSERT INTO households (id, name, created_at) VALUES (?, ?, ?)')
                ->execute([$householdId, 'Foyer de ' . $displayName, $now]);
        }

        $userId = Support::uuid();
        $pdo->prepare('INSERT INTO users (id, household_id, email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $householdId, $email, password_hash($password, PASSWORD_DEFAULT), $displayName, $now]);

        $user = self::findUser($userId);
        self::login($user);

        return $user;
    }

    public static function attempt(string $email, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password_hash'])) {
            throw HttpException::unauthorized('E-mail ou mot de passe incorrect.');
        }

        $user = self::publicUser($row);
        self::login($user);

        return $user;
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

    public static function createInvitation(string $householdId): array
    {
        $token = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable('now');
        Database::connection()
            ->prepare('INSERT INTO invitations (token, household_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([
                $token,
                $householdId,
                $now->format(DATE_ATOM),
                $now->modify('+14 days')->format(DATE_ATOM),
            ]);

        return ['token' => $token, 'expiresAt' => $now->modify('+14 days')->format(DATE_ATOM)];
    }

    private static function consumeInvitation(string $token): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM invitations WHERE token = ?');
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();

        if ($invitation === false) {
            throw HttpException::badRequest("Cette invitation n'existe pas.");
        }
        if ($invitation['used_at'] !== null) {
            throw HttpException::badRequest('Cette invitation a déjà été utilisée.');
        }
        if (new \DateTimeImmutable($invitation['expires_at']) < new \DateTimeImmutable('now')) {
            throw HttpException::badRequest('Cette invitation a expiré.');
        }

        $pdo->prepare('UPDATE invitations SET used_at = ? WHERE token = ?')
            ->execute([Support::now(), $token]);

        return $invitation['household_id'];
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
            'email' => $row['email'],
            'displayName' => $row['display_name'],
            'householdId' => $row['household_id'],
        ];
    }
}
