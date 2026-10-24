<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());

            return;
        }

        // Aucun tenant dans le contexte : on ne renvoie jamais de données (fail-closed).
        $builder->whereRaw('1 = 0');
    }
}
