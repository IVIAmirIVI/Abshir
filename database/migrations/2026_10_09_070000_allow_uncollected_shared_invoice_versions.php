<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared invoice versioning requires PostgreSQL.');
        }
        DB::statement('LOCK TABLE orders, invoices, invoice_collections, financial_transactions, order_financial_source_snapshots IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION shared_invoice_retirement_allowed(p_invoice bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS(SELECT 1 FROM invoices i WHERE i.id=p_invoice AND i.superseded_at IS NOT NULL
        AND ((i.status='VOIDED' AND i.voided_at IS NOT NULL AND i.voided_by IS NOT NULL AND btrim(i.void_reason)<>'')
          OR (i.status='REPLACED' AND i.replaced_at IS NOT NULL AND i.replaced_by IS NOT NULL AND btrim(i.replacement_reason)<>''))
        AND (SELECT contract FROM order_captain_selections WHERE order_id=i.order_id ORDER BY id DESC LIMIT 1)='SHARED_COMPANY_V1'
        AND NOT EXISTS(SELECT 1 FROM invoice_collections WHERE invoice_id=i.id)
        AND NOT EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='CUSTOMER_INVOICE' AND source_id=i.id::text));
$$;
SQL);
        $anchor = "    IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=parent_id AND purchase_mode='SHARED_TWO_V1')";
        $definition = $this->definition('shared_purchase_legacy_boundary()', $anchor);
        $extension = <<<'SQL'
    IF TG_TABLE_NAME='invoices' AND TG_OP='UPDATE' THEN
      IF parent_id=old_parent_id
       AND (SELECT contract FROM order_captain_selections WHERE order_id=parent_id ORDER BY id DESC LIMIT 1)='SHARED_COMPANY_V1'
       AND NEW.status IN ('VOIDED','REPLACED') THEN
        IF EXISTS(SELECT 1 FROM invoice_collections WHERE invoice_id=OLD.id)
           OR EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='CUSTOMER_INVOICE' AND source_id=OLD.id::text)
           OR (to_jsonb(NEW)-ARRAY['status','voided_at','voided_by','void_reason','replaced_at','replaced_by','replacement_reason','replaced_by_invoice_id','superseded_at','updated_at'])
                IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','voided_at','voided_by','void_reason','replaced_at','replaced_by','replacement_reason','replaced_by_invoice_id','superseded_at','updated_at']) THEN
            RAISE EXCEPTION 'Shared Invoice retirement cannot change financial facts or collected history' USING ERRCODE='23514';
        END IF;
        IF OLD.status='ISSUED' AND OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL
           AND ((NEW.status='VOIDED' AND NEW.voided_at IS NOT NULL AND NEW.voided_by IS NOT NULL AND btrim(NEW.void_reason)<>''
                AND (to_jsonb(NEW)-ARRAY['status','voided_at','voided_by','void_reason','superseded_at','updated_at'])=(to_jsonb(OLD)-ARRAY['status','voided_at','voided_by','void_reason','superseded_at','updated_at']))
             OR (NEW.status='REPLACED' AND NEW.replaced_at IS NOT NULL AND NEW.replaced_by IS NOT NULL AND btrim(NEW.replacement_reason)<>''
                AND (to_jsonb(NEW)-ARRAY['status','replaced_at','replaced_by','replacement_reason','superseded_at','updated_at'])=(to_jsonb(OLD)-ARRAY['status','replaced_at','replaced_by','replacement_reason','superseded_at','updated_at']))) THEN
            RETURN NEW;
        END IF;
        IF OLD.status='REPLACED' AND NEW.status='REPLACED' AND OLD.replaced_by_invoice_id IS NULL
           AND (to_jsonb(NEW)-ARRAY['replaced_by_invoice_id','updated_at'])=(to_jsonb(OLD)-ARRAY['replaced_by_invoice_id','updated_at'])
           AND EXISTS(SELECT 1 FROM invoices WHERE id=NEW.replaced_by_invoice_id AND order_id=OLD.order_id AND version>OLD.version) THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Shared Invoice retirement requires an audited uncollected current version' USING ERRCODE='23514';
      END IF;
    END IF;
SQL;
        DB::unprepared(str_replace($anchor, $extension."\n".$anchor, $definition));

        // Retain the exact frozen source and hash of an uncollected retired version.
        $anchor = "(i.status <> 'ISSUED' AND NOT EXISTS (SELECT 1 FROM invoice_financial_corrections c WHERE c.original_invoice_id=i.id AND c.state='COMPLETED'))";
        $definition = $this->definition('validate_financial_snapshot(bigint)', $anchor);
        DB::unprepared(str_replace($anchor,
            "(i.status <> 'ISSUED' AND NOT shared_invoice_retirement_allowed(i.id) AND NOT EXISTS (SELECT 1 FROM invoice_financial_corrections c WHERE c.original_invoice_id=i.id AND c.state='COMPLETED'))", $definition));
    }

    private function definition(string $signature, string $anchor): string
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(to_regprocedure(?))', [$signature]);
        if (! is_string($definition) || substr_count($definition, $anchor) !== 1) {
            throw new RuntimeException('Unexpected shared invoice guard: '.$signature);
        }

        return $definition;
    }

    public function down(): void
    {
        throw new RuntimeException('Shared Invoice versions are forward-only.');
    }
};
