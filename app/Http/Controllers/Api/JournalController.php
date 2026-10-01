<?php

namespace App\Http\Controllers\Api;

use App\Actions\Journal\PostEntry;
use App\Actions\Journal\SaveEntry;
use App\Actions\Journal\StornoEntry;
use App\Exceptions\DocumentLocked;
use App\Http\Requests\Ledger\JournalEntryRequest;
use App\Models\Firm;
use App\Models\JournalEntry;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Налози за книжење: нацрт, книжење, сторно. Логиката е во `App\Actions\Journal`. */
class JournalController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:draft,posted'],
            'type'   => ['nullable', 'integer'],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'to'     => ['nullable', 'date_format:Y-m-d'],
        ]);
        $q = trim((string) $request->query('q'));

        $page = JournalEntry::ofFirm()
            ->with('type')
            ->withSum('lines as sum_debit', 'debit')
            ->withSum('lines as sum_credit', 'credit')
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s))
            ->when($request->query('type'), fn ($b, $t) => $b->where('journal_type_id', $t))
            ->when($request->query('from'), fn ($b, $d) => $b->where('date', '>=', $d))
            ->when($request->query('to'), fn ($b, $d) => $b->where('date', '<=', $d))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('description', 'like', '%'.$q.'%')
                ->orWhereHas('lines', fn ($l) => $l->where('doc_number', 'like', '%'.$q.'%'))))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (JournalEntry $e) => $this->head($e, Money::cents($e->sum_debit), Money::cents($e->sum_credit)))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['entry' => $this->full($this->find($id))]);
    }

    public function store(JournalEntryRequest $request, SaveEntry $save): JsonResponse
    {
        $e = $save->run(Firm::current(), $request->user(), $request->validated());

        return response()->json(['entry' => $this->full($e)], 201);
    }

    public function update(JournalEntryRequest $request, int $id, SaveEntry $save): JsonResponse
    {
        $e = $save->run(Firm::current(), $request->user(), $request->validated(), $this->find($id));

        return response()->json(['entry' => $this->full($e)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $e = $this->find($id);
        if ($e->isPosted()) {
            throw new DocumentLocked('Прокнижен налог не се брише — само сторно.');
        }

        DB::transaction(fn () => $e->delete());

        return response()->json(['message' => 'Нацртот е избришан.']);
    }

    public function post(Request $request, int $id, PostEntry $post): JsonResponse
    {
        return response()->json(['entry' => $this->full($post->run($this->find($id), $request->user()))]);
    }

    public function storno(Request $request, int $id, StornoEntry $storno): JsonResponse
    {
        $data = $request->validate([
            'date'        => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['date' => 'датум', 'description' => 'опис']);

        $e = $storno->run($this->find($id), $request->user(), $data['date'] ?? null, $data['description'] ?? null);

        return response()->json(['entry' => $this->full($e)], 201);
    }

    private function find(int $id): JournalEntry
    {
        return JournalEntry::ofFirm()->findOrFail($id);
    }

    private function head(JournalEntry $e, int $debit, int $credit): array
    {
        $e->loadMissing('type');

        return [
            'id'             => $e->id,
            'type'           => ['id' => $e->type->id, 'code' => $e->type->code, 'name' => $e->type->name],
            'number'         => $e->number,
            'label'          => $e->label(),
            'date'           => $e->date->toDateString(),
            'year'           => (int) $e->year,
            'description'    => $e->description,
            'status'         => $e->status,
            'posting_seq'    => $e->posting_seq,
            'posted_at'      => $e->posted_at?->toIso8601String(),
            'posted_by'      => $e->poster?->name,
            'created_by'     => $e->creator?->name,
            'storno_of_id'   => $e->storno_of_id,
            'stornoed_by_id' => $e->stornoedBy?->id,
            'total_debit'    => Money::out($debit),
            'total_credit'   => Money::out($credit),
            'balanced'       => $debit === $credit,
        ];
    }

    private function full(JournalEntry $e): array
    {
        $e->loadMissing('type', 'lines.account', 'lines.partner', 'lines.costCenter');
        $d = $c = 0;

        $lines = $e->lines->map(function ($l) use (&$d, &$c) {
            $d += Money::cents($l->debit);
            $c += Money::cents($l->credit);

            return [
                'id'          => $l->id,
                'line_no'     => $l->line_no,
                'account'     => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name],
                'partner'     => $l->partner ? ['id' => $l->partner->id, 'name' => $l->partner->name] : null,
                'cost_center' => $l->costCenter ? ['id' => $l->costCenter->id, 'code' => $l->costCenter->code, 'name' => $l->costCenter->name] : null,
                'doc_number'  => $l->doc_number,
                'doc_date'    => $l->doc_date?->toDateString(),
                'due_date'    => $l->due_date?->toDateString(),
                'debit'       => Money::out(Money::cents($l->debit)),
                'credit'      => Money::out(Money::cents($l->credit)),
                'description' => $l->description,
            ];
        })->all();

        return $this->head($e, $d, $c) + ['lines' => $lines];
    }
}
