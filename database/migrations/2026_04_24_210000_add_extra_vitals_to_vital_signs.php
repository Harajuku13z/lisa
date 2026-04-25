<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->unsignedSmallInteger('respiratory_rate')->nullable()->after('oxygen_saturation');
            $table->decimal('blood_glucose', 5, 2)->nullable()->after('respiratory_rate');
            $table->unsignedTinyInteger('pain_level')->nullable()->after('blood_glucose');
            $table->decimal('weight', 5, 1)->nullable()->after('pain_level');
        });
    }

    public function down(): void {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn(['respiratory_rate', 'blood_glucose', 'pain_level', 'weight']);
        });
    }
};
