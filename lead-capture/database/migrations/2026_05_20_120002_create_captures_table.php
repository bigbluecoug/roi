<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('needs_review')->index();
            $table->string('image_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->timestamp('image_purged_at')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->string('organization')->nullable()->index();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->text('raw_text')->nullable();
            $table->json('confidence')->nullable();
            $table->json('evidence')->nullable();
            $table->json('extracted_payload')->nullable();
            $table->decimal('ai_confidence', 5, 2)->nullable();
            $table->decimal('match_confidence', 5, 2)->nullable();
            $table->text('match_reason')->nullable();
            $table->text('rep_notes')->nullable();
            $table->string('follow_up_status')->default('new');
            $table->string('hubspot_contact_id')->nullable();
            $table->string('hubspot_company_id')->nullable();
            $table->string('hubspot_note_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captures');
    }
};
