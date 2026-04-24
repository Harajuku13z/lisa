<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model {
    protected $fillable = ['day_id', 'number'];

    public function day(): BelongsTo { return $this->belongsTo(Day::class); }
    public function patients(): HasMany { return $this->hasMany(Patient::class); }
}
