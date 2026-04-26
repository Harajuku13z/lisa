<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('colleagues', function (Blueprint $table) {
            $table->id();
            // Owner of the colleague link (the nurse who added the colleague)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Linked colleague account once they sign up; null if invite still pending
            $table->foreignId('colleague_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            // pending_signup = invitee not registered yet | linked = both accounts connected
            $table->string('status', 32)->default('pending_signup');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
            $table->index('colleague_user_id');
        });
    }
    public function down(): void { Schema::dropIfExists('colleagues'); }
};
