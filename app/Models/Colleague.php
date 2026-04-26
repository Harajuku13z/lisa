<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Colleague extends Model
{
    protected $fillable = [
        'user_id',
        'colleague_user_id',
        'email',
        'name',
        'status',
        'invited_at',
        'linked_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'linked_at'  => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function colleagueUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'colleague_user_id');
    }
}
