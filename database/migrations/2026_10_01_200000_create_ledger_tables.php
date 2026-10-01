<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Јадрото на книговодството: контен план, партнери, места на трошок, видови
 | налози, налози со ставки, бројачи, заклучени периоди, затворања.
 |
 | Сите износи се decimal(15,2) — никогаш цел број (во ЕРП-от постојат МКД
 | документи со стотинки), никогаш float (збир од илјадници ставки во float
 | излегува со стотинка разлика, а бруто билансот мора да се затвори точно).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            // Само цифри. Должината е нивото: 1 класа, 2 група, 3 синтетика, 4+ аналитика.
            $table->string('code', 8);
            $table->string('name');
            // Од Правилникот: не се брише, шифрата и името не се менуваат.
            $table->boolean('is_statutory')->default(false);
            $table->boolean('needs_partner')->default(false);
            $table->boolean('needs_cost_center')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['firm_id', 'code']);
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->string('name');
            // ЕДБ; празен кај физички лица и некои странски. NULL не го крши unique.
            $table->string('tax_id', 13)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->default('MK');
            // Врска со клиентот во ЕРП-от (фаза 5) — не е надворешен клуч, друга база.
            $table->unsignedBigInteger('erp_customer_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['firm_id', 'tax_id']);
            $table->unique(['firm_id', 'erp_customer_id']);
            $table->index(['firm_id', 'name']);
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['firm_id', 'code']);
        });

        Schema::create('journal_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->string('code', 4);
            $table->string('name');
            // ПС — почетна состојба: во бруто билансот оди во „почетна“, не во „промет“.
            $table->boolean('is_opening')->default(false);
            $table->timestamps();
            $table->unique(['firm_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->foreignId('journal_type_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->string('description', 500)->nullable();
            $table->string('status', 10)->default('draft'); // draft | posted
            // Се доделуваат ПРИ КНИЖЕЊЕ, не при создавање: нацрт што ќе се избрише
            // не смее да остави дупка во бројот на налогот ниту во дневникот.
            $table->unsignedInteger('number')->nullable();       // по вид и година
            $table->unsignedInteger('posting_seq')->nullable();  // реден број во дневникот, по година
            $table->unsignedInteger('chain_seq')->nullable();    // ред во синџирот на хешови, по фирма
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            // Еден оригинал — најмногу едно сторно (unique).
            $table->foreignId('storno_of_id')->nullable()->unique()->constrained('journal_entries');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'year', 'journal_type_id', 'number']);
            $table->unique(['firm_id', 'year', 'posting_seq']);
            $table->unique(['firm_id', 'chain_seq']);
            $table->index(['firm_id', 'status', 'date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('cost_center_id')->nullable()->constrained();
            $table->string('doc_number', 50)->nullable();
            $table->date('doc_date')->nullable();
            $table->date('due_date')->nullable();
            // Сторното е „црвено“ — исти страни, негативни износи. Затоа без unsigned.
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'partner_id']);
            $table->index('partner_id');
        });

        // Бројачите се ред по фирма и клуч („chain“, „seq:2027“, „num:2027:3“).
        // Редот „chain“ се заклучува (SELECT … FOR UPDATE) при секое книжење —
        // така двајца што книжат истовремено се редат еден по друг и броевите
        // излегуваат без дупки и без двојници.
        Schema::create('journal_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->string('key', 40);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'key']);
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('locked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['firm_id', 'year', 'month']);
        });

        // Затворање на отворени ставки — аналитичка врска меѓу две ставки, не
        // книжење. Затоа може да се отвори одново без сторно.
        Schema::create('open_item_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained();
            $table->foreignId('line_a_id')->constrained('journal_lines');
            $table->foreignId('line_b_id')->constrained('journal_lines');
            $table->decimal('amount', 15, 2);
            $table->string('kind', 10)->default('manual'); // manual | storno
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('line_a_id');
            $table->index('line_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_item_matches');
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('journal_counters');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_types');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('accounts');
    }
};
