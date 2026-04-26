<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transmission extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'to_email',
        'mode',
        'source_date',
        'target_date',
        'payload',
        'message',
        'status',
        'accepted_at',
        'declined_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'source_date' => 'date:Y-m-d',
        'target_date' => 'date:Y-m-d',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
