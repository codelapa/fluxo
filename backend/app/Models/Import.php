<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $guarded = [];

    protected $hidden = ['path', 'checksum'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'completed_at' => 'datetime'];
    }
}
