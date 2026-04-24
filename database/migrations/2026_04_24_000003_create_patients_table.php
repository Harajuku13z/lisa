<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('gender', 5)->nullable();
            $table->text('diagnosis')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('patients'); }
};
