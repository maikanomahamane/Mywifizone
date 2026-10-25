<?php

namespace App\Services;

use App\Domain\Network\CommandType;
use App\Domain\Network\RouterOsDuration;
use App\Domain\Network\RouterOsValue;
use App\Infrastructure\RouterOs\RouterOsRestClient;
use InvalidArgumentException;

/**
 * Exécute une commande de la file via l'API REST de RouterOS (mode connexion directe). Équivalent PHP des gestionnaires de
 * l'agent (connector-agent/src/handlers.js) : mêmes types, mêmes résultats. Chaque valeur est revalidée par liste blanche et
 * n'est envoyée au routeur qu'en corps JSON (jamais de script ni de commande construite par concaténation).
 */
final class RouterOsCommandExecutor
{
    private const MB = 1048576;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException commande invalide (définitif : ne pas rejouer)
     */
    public function execute(RouterOsRestClient $ros, string $type, array $payload): array
    {
        return match ($type) {
            CommandType::SYNC_PROFILES => $this->syncProfiles($ros, $payload),
            CommandType::CREATE_VOUCHER => $this->createVouchers($ros, $payload),
            CommandType::DISABLE_VOUCHER => $this->disableVoucher($ros, $payload),
            CommandType::REVOKE_VOUCHER => $this->revokeVoucher($ros, $payload),
            CommandType::DISCONNECT_USER => $this->disconnect($ros, $payload),
            CommandType::FETCH_HEALTH => ['health' => self::health($ros->resource())],
            CommandType::FETCH_SESSIONS => self::collectSessions($ros),
            default => throw new InvalidArgumentException("Type de commande inconnu : $type"),
        };
    }

    /** @param array<string, mixed> $p */
    private function syncProfiles(RouterOsRestClient $ros, array $p): array
    {
        $profiles = $p['profiles'] ?? null;
        $this->assert(is_array($profiles) && count($profiles) <= 200, 'profiles invalide');

        $existing = [];
        foreach ($ros->profiles() as $row) {
            $existing[$row['name'] ?? ''] = $row;
        }

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? '';
            $rate = $profile['rate_limit'] ?? null;
            $shared = $profile['shared_users'] ?? null;
            $this->assert(is_string($name) && RouterOsValue::isValidProfileName($name), 'nom de profil invalide');
            $this->assert($rate === null || (is_string($rate) && RouterOsValue::isValidRateLimit($rate)), 'débit invalide');
            $this->assert(is_int($shared) && $shared >= 1 && $shared <= 20, 'shared-users invalide');

            $fields = ['shared-users' => (string) $shared];
            if ($rate !== null) {
                $fields['rate-limit'] = $rate;
            }
            if (isset($existing[$name])) {
                $ros->updateProfile((string) $existing[$name]['.id'], $rate !== null ? $fields : $fields + ['rate-limit' => '']);
            } else {
                $ros->createProfile(['name' => $name] + $fields);
            }
        }

        return [];
    }

    /** @param array<string, mixed> $p */
    private function createVouchers(RouterOsRestClient $ros, array $p): array
    {
        $vouchers = $p['vouchers'] ?? null;
        $this->assert(is_array($vouchers) && count($vouchers) >= 1 && count($vouchers) <= 50, 'vouchers invalide');

        $present = [];
        foreach ($ros->hotspotUsers() as $u) {
            $present[$u['name'] ?? ''] = true;
        }

        $created = [];
        $failed = [];
        foreach ($vouchers as $v) {
            $code = $v['code'] ?? '';
            $this->assert(is_string($code) && RouterOsValue::isValidCode($code), 'code invalide');
            $this->assert(is_string($v['profile'] ?? null) && RouterOsValue::isValidProfileName($v['profile']), 'profil invalide');
            $this->assert(is_string($v['voucher_id'] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $v['voucher_id']) === 1, 'voucher_id invalide');

            if (isset($present[$code])) {   // idempotent : rejeu après un résultat perdu
                $created[] = $code;

                continue;
            }

            $fields = ['name' => $code, 'password' => $code, 'profile' => $v['profile'], 'comment' => 'wz:'.$v['voucher_id']];
            if (! empty($v['limit_uptime_minutes'])) {
                $fields['limit-uptime'] = RouterOsDuration::formatMinutes((int) $v['limit_uptime_minutes']);
            }
            if (! empty($v['limit_bytes_total_mb'])) {
                $fields['limit-bytes-total'] = (string) ((int) $v['limit_bytes_total_mb'] * self::MB);
            }

            try {
                $ros->createUser($fields);
                $created[] = $code;
            } catch (\App\Infrastructure\RouterOs\RouterOsException $e) {
                // Routeur injoignable ou accès refusé : toute la commande échoue (réessayée), pas un code isolé.
                if (in_array($e->kind, ['unreachable', 'timeout', 'tls', 'auth', 'forbidden'], true)) {
                    throw $e;
                }
                $failed[] = ['code' => $code, 'error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** @param array<string, mixed> $p */
    private function disableVoucher(RouterOsRestClient $ros, array $p): array
    {
        $code = $this->code($p);
        if ($user = $this->findUser($ros, $code)) {
            $ros->updateUser((string) $user['.id'], ['disabled' => 'true']);
        }
        $this->dropActive($ros, $code);

        return [];
    }

    /** @param array<string, mixed> $p */
    private function revokeVoucher(RouterOsRestClient $ros, array $p): array
    {
        $code = $this->code($p);
        if ($user = $this->findUser($ros, $code)) {
            $ros->removeUser((string) $user['.id']);
        }
        $this->dropActive($ros, $code);

        return [];
    }

    /** @param array<string, mixed> $p */
    private function disconnect(RouterOsRestClient $ros, array $p): array
    {
        $sid = $p['session_id'] ?? '';
        $this->assert(is_string($sid) && preg_match('/^\*[0-9A-F]{1,16}$/D', $sid) === 1, 'session_id invalide');
        $username = $p['username'] ?? null;

        // Les identifiants RouterOS sont réutilisés : on vérifie qu'il s'agit bien de cet utilisateur.
        foreach ($ros->activeSessions() as $s) {
            if (($s['.id'] ?? null) === $sid && ($username === null || ($s['user'] ?? null) === $username)) {
                $ros->removeActive($sid);
            }
        }

        return [];
    }

    /** @param array<string, mixed> $p */
    private function code(array $p): string
    {
        $code = $p['code'] ?? '';
        $this->assert(is_string($code) && RouterOsValue::isValidCode($code), 'code invalide');

        return $code;
    }

    /** @return array<string, mixed>|null */
    private function findUser(RouterOsRestClient $ros, string $code): ?array
    {
        foreach ($ros->hotspotUsers() as $u) {
            if (($u['name'] ?? null) === $code) {
                return $u;
            }
        }

        return null;
    }

    private function dropActive(RouterOsRestClient $ros, string $code): void
    {
        foreach ($ros->activeSessions() as $s) {
            if (($s['user'] ?? null) === $code) {
                $ros->removeActive((string) $s['.id']);
            }
        }
    }

    private function assert(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $r  réponse de /system/resource
     * @return array<string, mixed>
     */
    public static function health(array $r): array
    {
        $out = [
            'board_name' => isset($r['board-name']) ? (string) $r['board-name'] : null,
            'version' => isset($r['version']) ? (string) $r['version'] : null,
            'cpu_load' => isset($r['cpu-load']) ? min(100, (int) $r['cpu-load']) : null,
            'free_memory' => isset($r['free-memory']) ? (int) $r['free-memory'] : null,
            'total_memory' => isset($r['total-memory']) ? (int) $r['total-memory'] : null,
            'uptime_seconds' => isset($r['uptime']) ? RouterOsDuration::parse($r['uptime']) : null,
        ];

        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * Sessions actives + codes « épuisés » (temps ou volume atteint) parmi les utilisateurs créés par WIFIZONE.
     *
     * @return array{sessions: array<int, array<string, mixed>>, finished_codes: array<int, string>}
     */
    public static function collectSessions(RouterOsRestClient $ros): array
    {
        $sessions = [];
        foreach (array_slice($ros->activeSessions(), 0, 1000) as $s) {
            $sessions[] = array_filter([
                'id' => (string) ($s['.id'] ?? ''),
                'username' => (string) ($s['user'] ?? ''),
                'address' => $s['address'] ?? null,
                'mac' => $s['mac-address'] ?? null,
                'uptime_seconds' => RouterOsDuration::parse($s['uptime'] ?? ''),
                'bytes_in' => (int) ($s['bytes-in'] ?? 0),
                'bytes_out' => (int) ($s['bytes-out'] ?? 0),
            ], fn ($v) => $v !== null);
        }

        $finished = [];
        foreach ($ros->hotspotUsers() as $u) {
            $name = (string) ($u['name'] ?? '');
            if (! str_starts_with((string) ($u['comment'] ?? ''), 'wz:') || ! RouterOsValue::isValidCode($name)) {
                continue;
            }
            $timeLimit = RouterOsDuration::parse($u['limit-uptime'] ?? '');
            $byteLimit = (int) ($u['limit-bytes-total'] ?? 0);
            $usedBytes = (int) ($u['bytes-in'] ?? 0) + (int) ($u['bytes-out'] ?? 0);
            if (($timeLimit > 0 && RouterOsDuration::parse($u['uptime'] ?? '') >= $timeLimit) || ($byteLimit > 0 && $usedBytes >= $byteLimit)) {
                $finished[] = $name;
            }
        }

        return ['sessions' => $sessions, 'finished_codes' => array_slice($finished, 0, 500)];
    }
}
