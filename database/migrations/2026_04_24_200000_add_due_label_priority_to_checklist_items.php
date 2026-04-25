<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->string('due_label')->nullable()->after('is_done');
            $table->string('priority')->default('normal')->after('due_label');
        });
    }

    public function down(): void {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn(['due_label', 'priority']);
        });
    }
};
