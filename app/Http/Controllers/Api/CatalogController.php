<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalog\Accounts;
use App\Actions\Journal\EntryRules;
use App\Exceptions\DocumentLocked;
use App\Http\Requests\Ledger\AccountRequest;
use App\Http\Requests\Ledger\CostCenterRequest;
use App\Http\Requests\Ledger\PartnerRequest;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Firm;
use App\Models\JournalLine;
use App\Models\JournalType;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Шифрарници на фирмата: контен план, партнери, места на трошок, видови налози. */
class CatalogController extends ApiController
{
    // ── Контен план ─────────────────────────────────────────────────────────

    public function accounts(Request $request): JsonResponse
    {
        $firm = Firm::current();
        $q = trim((string) $request->query('q'));

        $all = Account::ofFirm()->orderBy('code')->get();
        $withChildren = EntryRules::codesWithChildren($firm->id);
        $used = array_flip(JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.firm_id', $firm->id)
            ->distinct()->pluck('journal_lines.account_id')->all());

        $rows = $all->map(fn (Account $a) => $this->account($a, $withChildren, $used))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_starts_with($r['code'], $q) || mb_stripos($r['name'], $q) !== false))
            ->when($request->boolean('postable'), fn ($c) => $c->where('is_postable', true))
            ->when($request->boolean('active'), fn ($c) => $c->where('is_active', true))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function storeAccount(AccountRequest $request, Accounts $accounts): JsonResponse
    {
        $a = $accounts->create(Firm::current(), $request->validated());

        return response()->json(['account' => $this->accountOne($a)], 201);
    }

    public function updateAccount(AccountRequest $request, int $id, Accounts $accounts): JsonResponse
    {
        $a = $accounts->update(Account::ofFirm()->findOrFail($id), $request->validated());

        return response()->json(['account' => $this->accountOne($a)]);
    }

    public function deleteAccount(int $id, Accounts $accounts): JsonResponse
    {
        $accounts->delete(Account::ofFirm()->findOrFail($id));

        return response()->json(['message' => 'Контото е избришано.']);
    }

    // ── Партнери ────────────────────────────────────────────────────────────

    public function partners(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $page = Partner::ofFirm()
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('tax_id', 'like', $q.'%')))
            ->orderBy('name')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Partner $p) => $this->partner($p))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function storePartner(PartnerRequest $request): JsonResponse
    {
        $p = Partner::create(['firm_id' => Firm::current()->id] + $this->only($request->validated(), ['name', 'tax_id', 'address', 'city', 'country', 'is_active']));

        return response()->json(['partner' => $this->partner($p->fresh())], 201);
    }

    public function updatePartner(PartnerRequest $request, int $id): JsonResponse
    {
        $p = Partner::ofFirm()->findOrFail($id);
        $p->fill($this->only($request->validated(), ['name', 'tax_id', 'address', 'city', 'country', 'is_active']))->save();

        return response()->json(['partner' => $this->partner($p)]);
    }

    public function deletePartner(int $id): JsonResponse
    {
        $p = Partner::ofFirm()->findOrFail($id);
        if ($p->isUsed()) {
            throw new DocumentLocked('Партнерот има книжења — не се брише, може да се деактивира.');
        }
        $p->delete();

        return response()->json(['message' => 'Партнерот е избришан.']);
    }

    // ── Места на трошок ─────────────────────────────────────────────────────

    public function costCenters(): JsonResponse
    {
        return response()->json(['data' => CostCenter::ofFirm()->orderBy('code')->get()->map(fn ($c) => $this->costCenter($c))->all()]);
    }

    public function storeCostCenter(CostCenterRequest $request): JsonResponse
    {
        $c = CostCenter::create(['firm_id' => Firm::current()->id] + $this->only($request->validated(), ['code', 'name', 'is_active']));

        return response()->json(['cost_center' => $this->costCenter($c->fresh())], 201);
    }

    public function updateCostCenter(CostCenterRequest $request, int $id): JsonResponse
    {
        $c = CostCenter::ofFirm()->findOrFail($id);
        $c->fill($this->only($request->validated(), ['code', 'name', 'is_active']))->save();

        return response()->json(['cost_center' => $this->costCenter($c)]);
    }

    public function deleteCostCenter(int $id): JsonResponse
    {
        $c = CostCenter::ofFirm()->findOrFail($id);
        if ($c->isUsed()) {
            throw new DocumentLocked('Местото на трошок има книжења — не се брише, може да се деактивира.');
        }
        $c->delete();

        return response()->json(['message' => 'Местото на трошок е избришано.']);
    }

    public function journalTypes(): JsonResponse
    {
        return response()->json(['data' => JournalType::ofFirm()->orderBy('id')->get()
            ->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'is_opening' => $t->is_opening])->all()]);
    }

    // ── Облици ──────────────────────────────────────────────────────────────

    private function account(Account $a, array $withChildren, array $used): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'level' => $a->level(),
            'is_statutory' => $a->is_statutory,
            'is_postable' => strlen($a->code) >= 3 && ! isset($withChildren[$a->code]),
            'needs_partner' => $a->needs_partner, 'needs_cost_center' => $a->needs_cost_center,
            'is_active' => $a->is_active, 'used' => isset($used[$a->id]),
        ];
    }

    private function accountOne(Account $a): array
    {
        return $this->account($a, EntryRules::codesWithChildren($a->firm_id), $a->isUsed() ? [$a->id => true] : []);
    }

    private function partner(Partner $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'tax_id' => $p->tax_id, 'address' => $p->address,
            'city' => $p->city, 'country' => $p->country, 'erp_customer_id' => $p->erp_customer_id,
            'is_active' => (bool) $p->is_active, 'used' => $p->isUsed(),
        ];
    }

    private function costCenter(CostCenter $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => (bool) $c->is_active];
    }

    /** Само пратените полиња — непратено поле не смее да се избрише (правило 9). */
    private function only(array $data, array $keys): array
    {
        $out = array_intersect_key($data, array_flip($keys));
        if (isset($out['country'])) {
            $out['country'] = strtoupper($out['country']);
        }

        return array_filter($out, fn ($v, $k) => $v !== null || in_array($k, ['tax_id', 'address', 'city'], true), ARRAY_FILTER_USE_BOTH);
    }
}
