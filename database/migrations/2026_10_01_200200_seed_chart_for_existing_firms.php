<?php

use App\Models\Firm;
use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;

/*
 | Фирмите создадени пред фазата 2 немаат контен план ни видови налози.
 | Новите ги добиваат при создавање (`SaveFirm`); `ensure()` не допира фирма
 | што веќе ги има, па е безбедно и при повторно пуштање.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Firm::all() as $firm) {
            ChartOfAccounts::ensure($firm);
        }
    }

    public function down(): void
    {
        // Податоците остануваат: табелите ги брише претходната миграција.
    }
};
