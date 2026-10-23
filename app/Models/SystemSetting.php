<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server-wide credential saved in Admin → Credentials (see
 * App\Support\Credentials). Deliberately not workspace-scoped.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
