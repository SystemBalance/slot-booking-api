<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slot extends Model
{
    protected $fillable = ['capacity'];

    /**
     * Get all holds for this slot
     */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}
