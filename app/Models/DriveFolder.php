<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriveFolder extends Model
{
    protected $fillable = ['folder_id', 'name', 'enabled', 'last_listed_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_listed_at' => 'datetime',
        ];
    }

    public function url(): string
    {
        return 'https://drive.google.com/drive/folders/'.$this->folder_id;
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }
}
