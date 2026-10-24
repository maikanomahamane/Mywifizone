<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class TokenService
{
    /**
     * Émet une paire access (Sanctum, courte) + refresh (opaque, rotatif, stocké haché).
     *
     * @return array{token_type:string, access_token:string, access_expires_at:string, refresh_token:string, refresh_expires_at:string}
     */
    public function issue(User $user, ?string $deviceName, ?string $ip): array
    {
        $accessExpires = now()->addMinutes(config('wifizone.access_ttl_minutes'));
        $refreshExpires = now()->addDays(config('wifizone.refresh_ttl_days'));

        $access = $user->createToken($deviceName ?: 'device', ['*'], $accessExpires);

        $plainRefresh = Str::random(64);
        $refresh = RefreshToken::create([
            'user_id' => $user->getKey(),
            'access_token_id' => $access->accessToken->getKey(),
            'token_hash' => hash('sha256', $plainRefresh),
            'device_name' => $deviceName,
            'ip' => $ip,
            'expires_at' => $refreshExpires,
        ]);

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'access_expires_at' => $accessExpires->toIso8601String(),
            'refresh_token' => $refresh->getKey().'|'.$plainRefresh,
            'refresh_expires_at' => $refreshExpires->toIso8601String(),
        ];
    }

    /** Retrouve un refresh token à partir de la valeur envoyée par le client ("id|secret"). */
    public function find(string $presented): ?RefreshToken
    {
        if (! str_contains($presented, '|')) {
            return null;
        }

        [, $secret] = explode('|', $presented, 2);

        return RefreshToken::where('token_hash', hash('sha256', $secret))->first();
    }

    public function revokeAllFor(User $user): void
    {
        RefreshToken::where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
        PersonalAccessToken::where('tokenable_id', $user->getKey())->delete();
    }
}
