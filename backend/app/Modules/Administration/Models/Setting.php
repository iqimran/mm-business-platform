<?php

namespace App\Modules\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'description'])]
class Setting extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
