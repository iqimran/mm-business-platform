<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting view: one row per car with its financial position from ACTIVE records.
 * Mirrors App\Modules\Car\Support\FinancialFormulas exactly (parity is covered by tests):
 *   total_investment = purchase_cost + expenses_total
 *   profit           = sale_amount - purchase_cost - expenses_total   (NULL until sold)
 *   party_due        = sale_amount - party_received                    (NULL until sold)
 *   dealer_payable   = purchase amount - dealer_paid                   (NULL without purchase)
 * At most one active purchase/sale per car is guaranteed by partial unique indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW car_financial_positions AS
            SELECT
                c.id                AS car_id,
                c.branch_id,
                c.status,
                c.brand,
                c.model,
                c.model_year,
                c.chassis_number,
                c.registration_number,
                p.id                AS purchase_id,
                p.dealer_id,
                p.purchase_date,
                COALESCE(p.amount_minor, 0)                                AS purchase_cost,
                COALESCE(e.total, 0)                                       AS expenses_total,
                COALESCE(p.amount_minor, 0) + COALESCE(e.total, 0)         AS total_investment,
                s.id                AS sale_id,
                s.party_id,
                s.sale_date,
                s.amount_minor                                             AS sale_amount,
                CASE WHEN s.id IS NULL THEN NULL ELSE COALESCE(pp.total, 0) END                     AS party_received,
                CASE WHEN s.id IS NULL THEN NULL ELSE s.amount_minor - COALESCE(pp.total, 0) END    AS party_due,
                p.amount_minor                                             AS dealer_purchase_amount,
                CASE WHEN p.id IS NULL THEN NULL ELSE COALESCE(dp.total, 0) END                     AS dealer_paid,
                CASE WHEN p.id IS NULL THEN NULL ELSE p.amount_minor - COALESCE(dp.total, 0) END    AS dealer_payable,
                CASE WHEN s.id IS NULL THEN NULL
                     ELSE s.amount_minor - COALESCE(p.amount_minor, 0) - COALESCE(e.total, 0) END   AS profit
            FROM cars c
            LEFT JOIN car_purchases p ON p.car_id = c.id AND p.reversed_at IS NULL
            LEFT JOIN car_sales s ON s.car_id = c.id AND s.reversed_at IS NULL
            LEFT JOIN LATERAL (
                SELECT sum(x.amount_minor)::bigint AS total FROM car_expenses x WHERE x.car_id = c.id AND x.reversed_at IS NULL
            ) e ON true
            LEFT JOIN LATERAL (
                SELECT sum(x.amount_minor)::bigint AS total FROM car_party_payments x WHERE x.sale_id = s.id AND x.reversed_at IS NULL
            ) pp ON true
            LEFT JOIN LATERAL (
                SELECT sum(x.amount_minor)::bigint AS total FROM car_dealer_payments x WHERE x.purchase_id = p.id AND x.reversed_at IS NULL
            ) dp ON true;

            -- Date-range report queries across all branches (global users).
            CREATE INDEX car_sales_active_sale_date ON car_sales (sale_date) WHERE reversed_at IS NULL;
            CREATE INDEX car_expenses_active_expense_date ON car_expenses (expense_date) WHERE reversed_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS car_expenses_active_expense_date;
            DROP INDEX IF EXISTS car_sales_active_sale_date;
            DROP VIEW IF EXISTS car_financial_positions;
        SQL);
    }
};
