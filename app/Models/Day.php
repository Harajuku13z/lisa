<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Day extends Model {
    protected $fillable = ['user_id', 'date'];
    protected $casts = ['date' => 'date:Y-m-d'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function rooms(): HasMany { return $this->hasMany(Room::class); }
}
