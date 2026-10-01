<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ledger\OpenItems;
use App\Actions\Reports\Card;
use App\Actions\Reports\Integrity;
use App\Actions\Reports\JournalBook;
use App\Actions\Reports\Period;
use App\Actions\Reports\TrialBalance;
use App\Models\Account;
use App\Models\Firm;
use App\Models\OpenItemMatch;
use App\Models\Partner;
use App\Models\PeriodLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Периоди, отворени ставки, извештаи и картички. */
class LedgerController extends ApiController
{
    // ── Периоди ─────────────────────────────────────────────────────────────

    public function periods(Request $request): JsonResponse
    {
        $v = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($v['year'] ?? date('Y'));
        $locks = PeriodLock::ofFirm()->where('year', $year)->with('locker')->get()->keyBy('month');

        return response()->json(['year' => $year, 'data' => collect(range(1, 12))->map(fn ($m) => [
            'month'     => $m,
            'locked'    => $locks->has($m),
            'locked_at' => $locks->get($m)?->created_at?->toIso8601String(),
            'locked_by' => $locks->get($m)?->locker?->name,
        ])->all()]);
    }

    public function lock(Request $request): JsonResponse
    {
        $d = $this->month($request);
        PeriodLock::firstOrCreate(['firm_id' => Firm::current()->id, 'year' => $d['year'], 'month' => $d['month']], ['locked_by' => $request->user()->id]);

        return response()->json(['message' => sprintf('Месецот %02d/%d е заклучен.', $d['month'], $d['year'])]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $d = $this->month($request);
        PeriodLock::ofFirm()->where('year', $d['year'])->where('month', $d['month'])->delete();

        return response()->json(['message' => sprintf('Месецот %02d/%d е отклучен.', $d['month'], $d['year'])]);
    }

    private function month(Request $request): array
    {
        return $request->validate([
            'year'  => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ], [], ['year' => 'година', 'month' => 'месец']);
    }

    // ── Отворени ставки ─────────────────────────────────────────────────────

    public function openItems(Request $request, OpenItems $items): JsonResponse
    {
        $firm = Firm::current();
        $d = $request->validate([
            'partner_id' => ['required', 'integer', Rule::exists('partners', 'id')->where('firm_id', $firm->id)],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('firm_id', $firm->id)],
        ], [], ['partner_id' => 'партнер', 'account_id' => 'конто']);

        return response()->json(['data' => $items->list($firm, (int) $d['partner_id'], isset($d['account_id']) ? (int) $d['account_id'] : null, $request->boolean('all'))]);
    }

    public function match(Request $request, OpenItems $items): JsonResponse
    {
        $d = $request->validate([
            'line_id'       => ['required', 'integer'],
            'other_line_id' => ['required', 'integer'],
            'amount'        => ['nullable', 'numeric', 'decimal:0,2'],
        ]);

        $m = $items->match(Firm::current(), $request->user(), (int) $d['line_id'], (int) $d['other_line_id'], isset($d['amount']) ? (string) $d['amount'] : null);

        return response()->json(['match' => ['id' => $m->id, 'line_a_id' => $m->line_a_id, 'line_b_id' => $m->line_b_id, 'amount' => (float) $m->amount]], 201);
    }

    public function unmatch(int $id): JsonResponse
    {
        OpenItemMatch::ofFirm()->findOrFail($id)->delete();

        return response()->json(['message' => 'Затворањето е отворено.']);
    }

    // ── Извештаи ────────────────────────────────────────────────────────────

    public function trialBalance(Request $request, TrialBalance $tb): JsonResponse
    {
        $level = $request->validate(['level' => ['nullable', Rule::in(array_keys(TrialBalance::LEVELS))]])['level'] ?? 'analytic';

        return response()->json($tb->run(Firm::current(), $this->period($request), $level));
    }

    public function journalBook(Request $request, JournalBook $book): JsonResponse
    {
        return response()->json($book->journal(Firm::current(), $this->period($request)));
    }

    public function generalLedger(Request $request, JournalBook $book): JsonResponse
    {
        $id = $request->query('account_id') ? Account::ofFirm()->findOrFail((int) $request->query('account_id'))->id : null;

        return response()->json($book->generalLedger(Firm::current(), $this->period($request), $id));
    }

    public function integrity(Integrity $integrity): JsonResponse
    {
        return response()->json($integrity->run(Firm::current()));
    }

    public function accountCard(Request $request, int $id, Card $card): JsonResponse
    {
        $partner = $request->query('partner_id') ? Partner::ofFirm()->findOrFail((int) $request->query('partner_id'))->id : null;

        return response()->json($card->account(Firm::current(), Account::ofFirm()->findOrFail($id), $this->period($request), $partner));
    }

    public function partnerCard(Request $request, int $id, Card $card): JsonResponse
    {
        $account = $request->query('account_id') ? Account::ofFirm()->findOrFail((int) $request->query('account_id'))->id : null;

        return response()->json($card->partner(Firm::current(), Partner::ofFirm()->findOrFail($id), $this->period($request), $account));
    }

    private function period(Request $request): Period
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Period::fromInput($request->query('from'), $request->query('to'));
    }
}
