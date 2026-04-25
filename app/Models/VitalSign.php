<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model {
    protected $fillable = ['patient_id', 'temperature', 'blood_pressure', 'heart_rate', 'oxygen_saturation', 'respiratory_rate', 'blood_glucose', 'pain_level', 'weight', 'notes'];
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
}
