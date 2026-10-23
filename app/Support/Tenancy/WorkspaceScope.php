<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($id = Tenant::id()) !== null) {
            $builder->where($model->qualifyColumn('workspace_id'), $id);
        }
    }
}
