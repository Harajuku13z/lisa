<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model {
    protected $fillable = ['patient_id', 'temperature', 'blood_pressure', 'heart_rate', 'oxygen_saturation', 'notes'];
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
}
