<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL trigger to protect terminal waybill statuses.
 *
 * When a waybill is already DELIVERED, RETURNED, or CANCELLED:
 * - Status cannot be overwritten by a re-upload
 * - delivered_at / returned_at timestamps are preserved
 *
 * This lets us keep using fast bulk upsert() without risk
 * of older data overwriting newer terminal statuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION protect_waybill_terminal_status()
            RETURNS TRIGGER AS \$\$
            BEGIN
                -- If existing record has a terminal status, preserve it
                IF OLD.status IN ('DELIVERED', 'RETURNED', 'CANCELLED') THEN
                    NEW.status = OLD.status;
                    NEW.delivered_at = COALESCE(OLD.delivered_at, NEW.delivered_at);
                    NEW.returned_at = COALESCE(OLD.returned_at, NEW.returned_at);
                END IF;

                -- Never overwrite a valid delivered_at with null
                IF OLD.delivered_at IS NOT NULL AND NEW.delivered_at IS NULL THEN
                    NEW.delivered_at = OLD.delivered_at;
                END IF;

                -- Never overwrite a valid returned_at with null
                IF OLD.returned_at IS NOT NULL AND NEW.returned_at IS NULL THEN
                    NEW.returned_at = OLD.returned_at;
                END IF;

                -- Never overwrite a valid dispatched_at with null
                IF OLD.dispatched_at IS NOT NULL AND NEW.dispatched_at IS NULL THEN
                    NEW.dispatched_at = OLD.dispatched_at;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS waybill_protect_terminal ON waybills;

            CREATE TRIGGER waybill_protect_terminal
                BEFORE UPDATE ON waybills
                FOR EACH ROW
                EXECUTE FUNCTION protect_waybill_terminal_status();
        ");
    }

    public function down(): void
    {
        DB::unprepared("
            DROP TRIGGER IF EXISTS waybill_protect_terminal ON waybills;
            DROP FUNCTION IF EXISTS protect_waybill_terminal_status();
        ");
    }
};
