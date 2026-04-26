<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transmissions', function (Blueprint $table) {
            $table->id();
            // Sender
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            // Recipient (nullable for email-only transmissions)
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_email')->nullable();

            // mode: 'email' (HTML email only) | 'colleague' (in-app) | 'self' (copy to own future date)
            $table->string('mode', 16);

            // Day being transmitted (source) and day where data should land (target)
            $table->date('source_date');
            $table->date('target_date');

            // Frozen snapshot of rooms/patients/vitals/checklist (no personal voice notes).
            // Stored as JSON so we can rebuild the day even if source records change later.
            $table->json('payload');

            // Optional handoff message (e.g. "JM est instable, surveiller la TA")
            $table->text('message')->nullable();

            // sent | accepted | declined  — applies only when mode = colleague/self
            $table->string('status', 16)->default('sent');

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->index(['to_user_id', 'status']);
            $table->index(['from_user_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('transmissions'); }
};
