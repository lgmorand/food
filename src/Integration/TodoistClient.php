<?php

declare(strict_types=1);

namespace Food\Integration;

use Closure;
use Food\Http\HttpException;
use Food\Support;

final class TodoistClient
{
    private readonly string $token;
    private readonly string $projectId;

    /** @param ?Closure(string, array, string): array{status: int, body: string} $transport */
    public function __construct(
        ?string $token = null,
        ?string $projectId = null,
        private readonly ?Closure $transport = null,
    ) {
        $this->token = trim($token ?? (getenv('FOOD_TODOIST_TOKEN') ?: ''));
        $this->projectId = trim($projectId ?? (getenv('FOOD_TODOIST_PROJECT_ID') ?: ''));
    }

    public function configuration(): array
    {
        $missing = [];
        if ($this->token === '') {
            $missing[] = 'FOOD_TODOIST_TOKEN';
        }
        if ($this->projectId === '') {
            $missing[] = 'FOOD_TODOIST_PROJECT_ID';
        }

        return ['configured' => $missing === [], 'missing' => $missing];
    }

    public function push(array $list): int
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            throw HttpException::conflict(
                'Todoist non configuré : renseignez ' . implode(' et ', $configuration['missing']) . ' sur le serveur.'
            );
        }

        $commands = [];
        foreach ($list['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $commands[] = [
                    'type' => 'item_add',
                    'uuid' => Support::uuid(),
                    'temp_id' => Support::uuid(),
                    'args' => [
                        'project_id' => $this->projectId,
                        'content' => $item['label'] . ($item['display'] !== '' ? ' — ' . $item['display'] : ''),
                        'description' => 'Food — semaine du ' . $list['weekStart'] . "\nRayon : " . $group['label'],
                    ],
                ];
            }
        }
        if ($commands === []) {
            throw HttpException::badRequest('La liste de courses est vide.');
        }

        $created = 0;
        $total = count($commands);
        foreach (array_chunk($commands, 100) as $batch) {
            $body = http_build_query([
                'commands' => json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], '', '&', PHP_QUERY_RFC3986);
            if (strlen($body) > 1048576) {
                throw $this->failure('La liste dépasse la taille maximale acceptée par Todoist.', $created, $total);
            }

            $response = $this->request($body);
            if ($response['status'] === 0 || $response['status'] >= 500) {
                throw $this->failure('Confirmation Todoist indisponible.', $created, $total, true);
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $message = match ($response['status']) {
                    401 => 'Clé API Todoist invalide ou expirée.',
                    403 => 'Le compte Todoist ne peut pas ajouter de tâches dans ce projet.',
                    404 => 'Projet Todoist introuvable.',
                    429 => 'Limite de requêtes Todoist atteinte. Attendez avant un nouvel envoi.',
                    default => 'Envoi refusé par Todoist (HTTP ' . $response['status'] . ').',
                };
                throw $this->failure($message, $created, $total);
            }

            $payload = json_decode($response['body'], true);
            if (!is_array($payload) || !isset($payload['sync_status']) || !is_array($payload['sync_status'])) {
                throw $this->failure('Réponse Todoist invalide.', $created, $total, true);
            }

            $failed = false;
            $uncertain = false;
            foreach ($batch as $command) {
                $status = $payload['sync_status'][$command['uuid']] ?? null;
                if ($status === 'ok') {
                    $created++;
                } else {
                    $failed = true;
                    $uncertain = $uncertain || !is_array($status);
                }
            }
            if ($failed) {
                throw $this->failure(
                    'Todoist n’a pas accepté tous les articles. Vérifiez le projet et les droits du compte.',
                    $created,
                    $total,
                    $uncertain
                );
            }
        }

        return $created;
    }

    private function request(string $body): array
    {
        $url = 'https://api.todoist.com/api/v1/sync';
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body);
        }
        if (!extension_loaded('curl')) {
            throw new HttpException('L’extension PHP cURL est nécessaire pour envoyer la liste à Todoist.', 503);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        // Ne pas rejouer une écriture dont la réponse a été perdue.
        $response = curl_exec($curl);
        $status = $response === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => $response === false ? '' : $response];
    }

    private function failure(string $message, int $created, int $total, bool $uncertain = false): HttpException
    {
        $message .= " {$created} / {$total} création(s) confirmée(s).";
        if ($uncertain) {
            $message .= ' D’autres tâches ont peut-être été créées.';
        }
        $message .= ' Un nouvel envoi peut créer des doublons.';

        return new HttpException($message, 502);
    }
}
