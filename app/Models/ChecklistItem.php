<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model {
    protected $fillable = ['patient_id', 'title', 'is_done', 'due_label', 'priority'];
    protected $casts = ['is_done' => 'boolean'];
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
}
