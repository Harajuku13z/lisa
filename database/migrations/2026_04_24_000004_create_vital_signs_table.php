<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->string('blood_pressure', 20)->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->decimal('oxygen_saturation', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('vital_signs'); }
};
