<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Règles de validation réutilisables. */
final class Rules
{
    /**
     * L'identifiant doit désigner une ressource DU TENANT COURANT (le scope global s'applique). Une ressource inexistante
     * et une ressource d'un autre tenant donnent exactement la même erreur : pas de fuite d'existence.
     * À combiner avec la règle `uuid` (sinon PostgreSQL rejette une valeur mal formée).
     *
     * @param  class-string<Model>  $model
     */
    public static function ownedBy(string $model): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model): void {
            if (! is_string($value) || ! \Illuminate\Support\Str::isUuid($value) || ! $model::query()->whereKey($value)->exists()) {
                $fail('Ressource introuvable.');
            }
        };
    }

    /** Taille de page demandée, bornée. */
    public static function perPage(Request $request, int $default = 25): int
    {
        return max(1, min((int) $request->integer('per_page', $default), 100));
    }
}
