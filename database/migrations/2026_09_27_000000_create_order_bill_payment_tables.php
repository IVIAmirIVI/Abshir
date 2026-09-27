<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payment_fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('fixed_fee', 18, 4);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->foreignId('superseded_by')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['currency_id', 'effective_from']);
        });
        Schema::table('bill_payment_fee_rules', function (Blueprint $table): void {
            $table->foreign('superseded_by')->references('id')->on('bill_payment_fee_rules')->restrictOnDelete();
        });

        Schema::create('order_bill_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_rule_id')->constrained('bill_payment_fee_rules')->restrictOnDelete();
            $table->decimal('service_fee', 18, 4);
            $table->string('status', 16)->default('REQUESTED');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('order_bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('order_bill_payment_requests')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_rule_id')->constrained('bill_payment_fee_rules')->restrictOnDelete();
            $table->string('bill_number', 191);
            $table->date('bill_date');
            $table->string('provider_name', 191)->nullable();
            $table->decimal('principal_amount', 18, 4);
            $table->decimal('service_fee', 18, 4);
            $table->decimal('total_customer_cash', 18, 4);
            $table->timestampTz('delivered_at');
            $table->timestampTz('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 191);
            $table->timestamps();
            $table->index('order_id');
        });

        Schema::create('order_bill_payment_principal_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_payment_id')->unique()->constrained('order_bill_payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('principal_amount', 18, 4);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamps();
        });

        Schema::create('order_bill_payment_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_payment_id')->constrained('order_bill_payments')->restrictOnDelete();
            $table->string('storage_path', 1000)->unique();
            $table->string('original_filename', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE bill_payment_fee_rules ADD CONSTRAINT bill_payment_fee_positive CHECK (fixed_fee > 0), ADD CONSTRAINT bill_payment_fee_interval CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement("ALTER TABLE bill_payment_fee_rules ADD CONSTRAINT bill_payment_fee_no_overlap EXCLUDE USING gist (currency_id WITH =, tstzrange(effective_from, effective_until, '[)') WITH &&)");
            DB::statement("ALTER TABLE order_bill_payment_requests ADD CONSTRAINT bill_payment_request_status_valid CHECK (status IN ('REQUESTED', 'COMPLETED', 'CANCELLED')), ADD CONSTRAINT bill_payment_request_fee_positive CHECK (service_fee > 0), ADD CONSTRAINT bill_payment_request_state_shape CHECK ((status = 'REQUESTED' AND completed_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL) OR (status = 'COMPLETED' AND completed_at IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL))");
            DB::statement('ALTER TABLE order_bill_payments ADD CONSTRAINT bill_payment_amounts_valid CHECK (principal_amount > 0 AND service_fee > 0 AND total_customer_cash = principal_amount + service_fee)');
            DB::statement('ALTER TABLE order_bill_payment_principal_handovers ADD CONSTRAINT bill_payment_handover_amount_positive CHECK (principal_amount > 0)');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_bill_payment_history_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Completed bill-payment history is immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER immutable_order_bill_payment BEFORE UPDATE OR DELETE ON order_bill_payments FOR EACH ROW EXECUTE FUNCTION reject_bill_payment_history_mutation();
CREATE TRIGGER immutable_bill_payment_handover BEFORE UPDATE OR DELETE ON order_bill_payment_principal_handovers FOR EACH ROW EXECUTE FUNCTION reject_bill_payment_history_mutation();
CREATE TRIGGER immutable_bill_payment_evidence BEFORE UPDATE OR DELETE ON order_bill_payment_evidence FOR EACH ROW EXECUTE FUNCTION reject_bill_payment_history_mutation();
SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment financial history is intentionally forward-only.');
    }
};
