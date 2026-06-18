<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        -- Issued invoices: block deletes; freeze financial columns.
        CREATE OR REPLACE FUNCTION billify_invoice_immutable() RETURNS trigger AS $$
        BEGIN
          IF OLD.state <> 'draft' THEN
            IF TG_OP = 'DELETE' THEN
              RAISE EXCEPTION 'billify: issued invoice % cannot be deleted', OLD.id;
            END IF;
            IF NEW.currency <> OLD.currency OR NEW.subtotal_minor <> OLD.subtotal_minor
               OR NEW.total_minor <> OLD.total_minor OR NEW.tax_minor <> OLD.tax_minor THEN
              RAISE EXCEPTION 'billify: issued invoice % financials are immutable', OLD.id;
            END IF;
          END IF;
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER billify_invoices_immutable BEFORE UPDATE OR DELETE ON billify_invoices
          FOR EACH ROW EXECUTE FUNCTION billify_invoice_immutable();

        -- Lines of a non-draft invoice are frozen entirely.
        CREATE OR REPLACE FUNCTION billify_line_immutable() RETURNS trigger AS $$
        DECLARE st billify_invoice_state;
        BEGIN
          SELECT state INTO st FROM billify_invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
          IF st IS NOT NULL AND st <> 'draft' THEN
            RAISE EXCEPTION 'billify: lines of issued invoice are immutable';
          END IF;
          RETURN COALESCE(NEW, OLD);
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER billify_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON billify_invoice_lines
          FOR EACH ROW EXECUTE FUNCTION billify_line_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS billify_lines_immutable ON billify_invoice_lines;
        DROP TRIGGER IF EXISTS billify_invoices_immutable ON billify_invoices;
        DROP FUNCTION IF EXISTS billify_line_immutable();
        DROP FUNCTION IF EXISTS billify_invoice_immutable();
        SQL);
    }
};
