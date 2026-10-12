<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captain_benefit_notification_events', function (Blueprint $table) {
            $table->foreignId('review_id')->primary()->constrained('captain_benefit_reviews')->restrictOnDelete();
            $table->jsonb('recipient_ids');
            $table->timestampTz('delivered_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE captain_benefit_notification_events ADD CONSTRAINT benefit_notice_recipients_array CHECK (jsonb_typeof(recipient_ids) = 'array')");
    }

    public function down(): void
    {
        throw new LogicException('Workflow notification delivery history is forward-only.');
    }
};
