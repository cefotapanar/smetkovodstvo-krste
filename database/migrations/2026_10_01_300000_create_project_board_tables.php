<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | ПРИВРЕМЕНО — „Работна табла на проектот“ (страницата `/`).
 |
 | Место каде сопственикот и сметководителката (ТИА Конто) го читаат планот и
 | одговараат на прашања додека трае изработката. Кога проектот ќе заврши,
 | целиот дел се брише (види CLAUDE.md → „Работна табла“) — затоа ништо од
 | книговодството не смее да зависи од овие табели.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_items', function (Blueprint $table) {
            $table->id();
            // Стабилен клуч за ставките што доаѓаат со кодот (database/data/projekt.php);
            // NULL за ставките внесени на страницата.
            $table->string('key', 60)->nullable()->unique();
            $table->string('side', 12);                 // owner | accountant — кој одлучува
            $table->string('kind', 12)->default('question'); // question | decision | note
            $table->string('phase', 20)->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 10)->default('open'); // open | decided
            $table->text('decision')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable(); // „во разговор“ — одлука донесена надвор од страницата
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // На која страна од таблата одлучува корисникот (сопственик / сметководител).
            $table->string('project_side', 12)->nullable();
            // За ознаката „НОВО“ — што се случило од последната посета.
            $table->timestamp('project_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['project_side', 'project_seen_at']);
        });
        Schema::dropIfExists('project_comments');
        Schema::dropIfExists('project_items');
    }
};
