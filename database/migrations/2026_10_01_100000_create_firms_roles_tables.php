<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Фирми и пристап по фирма.
 |
 | Засега се води една фирма, но `firm_id` е тука од првиот ден: ако ТИА Конто
 | подоцна води и свои клиенти, додавањето фирма не смее да значи препишување
 | на секоја табела во книгите.
 |
 | Улогата е врзана за ПАРОТ корисник–фирма (firm_user.role_id), не за
 | корисникот: сметководител може да книжи во една фирма, а во друга само да
 | гледа. Улогите се заеднички шаблони за сите фирми.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 60)->nullable();
            // ЕДБ — 13 цифри; со него се спојуваат партнерите и е-Фактура.
            $table->string('tax_id', 13)->unique();
            $table->string('reg_no', 20)->nullable();          // ЕМБС
            $table->string('activity_code', 10)->nullable();   // НКД — главна приходна шифра за СПД
            // micro/small/medium/large — одредува кои извештаи се составуваат.
            $table->string('size', 10)->default('small');
            // month/quarter (чл. 39 од ЗДДВ: тримесечје до 25 милиони промет лани).
            // Може да се смени со годината — во фаза 4 станува податок по година.
            $table->string('vat_period', 10)->default('month');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('firm_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Без улога = пристап до фирмата без ниедно право (се гледа во
            // списокот, ништо друго). Бришење на улога не смее тивко да даде
            // повеќе права, затоа nullOnDelete, а не cascade.
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['firm_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('firms');
    }
};
