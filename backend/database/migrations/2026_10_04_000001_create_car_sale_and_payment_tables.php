<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales, party (customer) payments and dealer payments. Same rules as purchases/expenses:
 * integer minor units, immutable rows, one-time reversal enforced by car_financial_record_guard().
 *
 * Party Due      = sale amount     - active party payments  (car_party_payments.sale_id)
 * Dealer Payable = purchase amount - active dealer payments (car_dealer_payments.purchase_id)
 */
return new class extends Migration
{
    private const STATUSES = ['PURCHASED', 'IN_STOCK', 'PREPARATION', 'READY_FOR_SALE', 'SOLD', 'COMPLETED'];

    private const METHODS = ['cash', 'bank_transfer', 'cheque', 'mobile_banking', 'other'];

    public function up(): void
    {
        Schema::create('car_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('car_id')->constrained('cars')->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('party_id')->constrained('car_parties')->restrictOnDelete();
            $table->date('sale_date');
            $table->bigInteger('amount_minor');
            // Restored when the sale is reversed.
            $table->string('status_before_sale', 30);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->index(['branch_id', 'sale_date']);
            $table->index('party_id');
        });

        foreach (['car_party_payments' => ['sale_id', 'car_sales', 'party_id', 'car_parties'], 'car_dealer_payments' => ['purchase_id', 'car_purchases', 'dealer_id', 'car_dealers']] as $name => [$parentKey, $parentTable, $counterpartyKey, $counterpartyTable]) {
            Schema::create($name, function (Blueprint $table) use ($parentKey, $parentTable, $counterpartyKey, $counterpartyTable) {
                $table->ulid('id')->primary();
                $table->foreignUlid($parentKey)->constrained($parentTable)->restrictOnDelete();
                $table->foreignUlid('car_id')->constrained('cars')->restrictOnDelete();
                $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignUlid($counterpartyKey)->constrained($counterpartyTable)->restrictOnDelete();
                $table->date('payment_date');
                $table->bigInteger('amount_minor');
                $table->string('method', 30);
                $table->string('reference', 100)->nullable();
                $table->text('notes')->nullable();
                $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('reversed_at')->nullable();
                $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('reversal_reason', 500)->nullable();

                $table->index([$parentKey]);
                $table->index(['branch_id', 'payment_date']);
                $table->index($counterpartyKey);
            });
        }

        $statuses = implode(', ', array_map(fn (string $s) => "'{$s}'", self::STATUSES));
        DB::statement("ALTER TABLE car_sales ADD CONSTRAINT car_sales_status_before_check CHECK (status_before_sale IN ({$statuses}) AND status_before_sale NOT IN ('SOLD', 'COMPLETED'))");
        DB::statement('CREATE UNIQUE INDEX car_sales_one_active_per_car ON car_sales (car_id) WHERE reversed_at IS NULL');

        $methods = implode(', ', array_map(fn (string $m) => "'{$m}'", self::METHODS));
        foreach (['car_sales', 'car_party_payments', 'car_dealer_payments'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amount_positive_check CHECK (amount_minor > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reversal_complete_check CHECK (
                (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
            )");
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION car_financial_record_guard()");

            if ($table !== 'car_sales') {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_method_check CHECK (method IN ({$methods}))");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('car_dealer_payments');
        Schema::dropIfExists('car_party_payments');
        Schema::dropIfExists('car_sales');
    }
};
