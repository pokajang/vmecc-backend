<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateAssignment = DB::table('salary_assignments')
            ->select('employee_user_id', 'effective_from', DB::raw('COUNT(*) as aggregate'))
            ->whereNull('deleted_at')
            ->groupBy('employee_user_id', 'effective_from')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateAssignment) {
            throw new RuntimeException(sprintf(
                'Payroll hardening blocked: employee %s has duplicate salary assignments effective on %s.',
                $duplicateAssignment->employee_user_id,
                $duplicateAssignment->effective_from,
            ));
        }

        $salaryPeriods = DB::table('payroll_claims')
            ->where('claim_type', 'salary')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')
            ->get(['id', 'user_id', 'period_value']);
        $invalidSalaryPeriod = $salaryPeriods->first(
            fn (object $row) => ! preg_match('/^\d{4}-\d{2}$/', (string) $row->period_value),
        );
        if ($invalidSalaryPeriod) {
            throw new RuntimeException(sprintf(
                'Payroll hardening blocked: active salary claim %s has an invalid payroll period.',
                $invalidSalaryPeriod->id,
            ));
        }
        $duplicateSalaryPeriod = $salaryPeriods
            ->groupBy(fn (object $row) => $row->user_id.'|'.$row->period_value)
            ->first(fn ($rows) => $rows->count() > 1);
        if ($duplicateSalaryPeriod) {
            $first = $duplicateSalaryPeriod->first();
            throw new RuntimeException(sprintf(
                'Payroll hardening blocked: employee %s has duplicate active salary claims for %s.',
                $first->user_id,
                $first->period_value,
            ));
        }

        Schema::table('salary_assignments', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->after('id');
            $table->date('effective_date_key')->nullable()->after('effective_from');
            $table->unsignedInteger('version')->default(1)->after('updated_by');
            $table->unique('public_id', 'salary_assignments_public_id_unique');
            $table->unique(
                ['employee_user_id', 'effective_date_key'],
                'salary_assignments_employee_effective_unique'
            );
            $table->index(
                ['employee_user_id', 'effective_from', 'status'],
                'salary_assignments_effective_lookup'
            );
        });

        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->after('id');
            $table->string('salary_period_key', 7)->nullable()->after('period_value');
            $table->foreignId('salary_assignment_id')
                ->nullable()
                ->after('payroll_snapshot')
                ->constrained('salary_assignments')
                ->nullOnDelete();
            $table->unsignedInteger('salary_assignment_version')->nullable()->after('salary_assignment_id');
            $table->string('calculation_engine_version', 40)->nullable()->after('salary_assignment_version');
            $table->unique('public_id', 'payroll_claims_public_id_unique');
            $table->unique(
                ['user_id', 'salary_period_key'],
                'payroll_claims_user_salary_period_unique'
            );
        });

        DB::table('salary_assignments')
            ->whereNull('public_id')
            ->orderBy('id')
            ->each(fn (object $row) => DB::table('salary_assignments')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::ulid()]));
        DB::table('salary_assignments')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(fn (object $row) => DB::table('salary_assignments')
                ->where('id', $row->id)
                ->update(['effective_date_key' => $row->effective_from]));

        DB::table('payroll_claims')
            ->whereNull('public_id')
            ->orderBy('id')
            ->each(fn (object $row) => DB::table('payroll_claims')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::ulid()]));

        foreach ($salaryPeriods as $row) {
            DB::table('payroll_claims')
                ->where('id', $row->id)
                ->update(['salary_period_key' => (string) $row->period_value]);
        }

        Schema::table('salary_assignments', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable(false)->change();
        });
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropUnique('payroll_claims_user_salary_period_unique');
            $table->dropUnique('payroll_claims_public_id_unique');
            $table->dropForeign(['salary_assignment_id']);
            $table->dropColumn([
                'public_id',
                'salary_period_key',
                'salary_assignment_id',
                'salary_assignment_version',
                'calculation_engine_version',
            ]);
        });

        Schema::table('salary_assignments', function (Blueprint $table) {
            $table->dropIndex('salary_assignments_effective_lookup');
            $table->dropUnique('salary_assignments_employee_effective_unique');
            $table->dropUnique('salary_assignments_public_id_unique');
            $table->dropColumn(['public_id', 'effective_date_key', 'version']);
        });
    }
};
