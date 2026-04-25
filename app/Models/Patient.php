<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model {
    protected $fillable = ['room_id', 'name', 'full_name', 'initials', 'age', 'gender', 'diagnosis'];

    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function vitals(): HasMany { return $this->hasMany(VitalSign::class)->latest(); }
    public function voiceNotes(): HasMany { return $this->hasMany(VoiceNote::class)->latest(); }
    public function checklistItems(): HasMany { return $this->hasMany(ChecklistItem::class); }
}
