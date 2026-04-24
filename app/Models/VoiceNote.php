<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceNote extends Model {
    protected $fillable = ['user_id', 'patient_id', 'raw_text', 'structured_text'];
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
