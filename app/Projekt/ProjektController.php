<?php

namespace App\Projekt;

use App\Actions\System\SaveUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use League\CommonMark\Extension\Attributes\AttributesExtension;

/**
 * ПРИВРЕМЕНО — „Работна табла на проектот“: планот + прашања и одлуки.
 *
 * Единствениот веб-екран со сесија во целиот сервер. Сметководствената работа
 * НЕ оди тука (таа е во Windows програмата преку API) — ова е место за
 * договарање додека трае изработката, и се брише кога ќе завршиме.
 */
class ProjektController extends Controller
{
    // ── Најава ──────────────────────────────────────────────────────────────

    public function loginForm(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('projekt') : view('projekt.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ], ['email.required' => 'Внесете е-пошта.', 'email.email' => 'Е-поштата не е во исправен облик.', 'password.required' => 'Внесете лозинка.']);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], true)) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => 'Погрешна е-пошта или лозинка.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('projekt'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ── Таблата ─────────────────────────────────────────────────────────────

    public function board(Request $request): View
    {
        ProjectSync::ifChanged();

        $user = $request->user();
        $seen = $user->project_seen_at;

        $items = ProjectItem::with('comments.user', 'decider', 'creator')
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('sort')->orderBy('id')
            ->get();

        // „НОВО“ = се случило по последната посета и не го направил самиот корисник.
        $isNew = function (ProjectItem $i) use ($seen, $user): bool {
            if (! $seen) {
                return false;
            }
            $fresh = ($i->created_at > $seen && $i->created_by !== $user->id)
                || ($i->decided_at > $seen && $i->decided_by !== $user->id);

            return $fresh || $i->comments->contains(fn ($c) => $c->created_at > $seen && $c->user_id !== $user->id);
        };

        $user->forceFill(['project_seen_at' => now()])->save();

        [$planHtml, $toc] = $this->plan();

        return view('projekt.board', [
            'user'      => $user,
            'mySide'    => self::sideOf($user),
            'columns'   => [
                'owner'      => $items->where('side', 'owner')->values(),
                'accountant' => $items->where('side', 'accountant')->values(),
            ],
            'isNew'     => $isNew,
            'planHtml'  => $planHtml,
            'toc'       => $toc,
            'users'     => $user->isSuper() ? User::orderBy('name')->get() : collect(),
            'canDecide' => fn (ProjectItem $i) => self::canDecide($user, $i),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body'  => ['nullable', 'string', 'max:5000'],
            'side'  => ['required', Rule::in(array_keys(ProjectItem::SIDES))],
            'kind'  => ['required', Rule::in(array_keys(ProjectItem::KINDS))],
        ], [], ['title' => 'наслов', 'body' => 'текст', 'side' => 'за кого']);

        $item = ProjectItem::create($data + ['status' => 'open', 'created_by' => $request->user()->id, 'sort' => 1000]);

        return redirect()->to(route('projekt').'#stavka-'.$item->id)->with('ok', 'Прашањето е поставено.');
    }

    public function comment(Request $request, ProjectItem $item): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Коментарот е празен.']);

        $item->comments()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);
        $item->touch();

        return redirect()->to(route('projekt').'#stavka-'.$item->id)->with('ok', 'Коментарот е запишан.');
    }

    public function decide(Request $request, ProjectItem $item): RedirectResponse
    {
        abort_unless(self::canDecide($request->user(), $item), 403, 'Ова прашање го одлучува другата страна.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Напишете ја одлуката.']);

        DB::transaction(function () use ($item, $data, $request) {
            $item->update([
                'status' => 'decided', 'decision' => $data['body'], 'decided_by' => $request->user()->id,
                'decided_by_name' => null, 'decided_at' => now(),
            ]);
        });

        return redirect()->to(route('projekt').'#stavka-'.$item->id)->with('ok', 'Одлуката е запишана.');
    }

    public function reopen(Request $request, ProjectItem $item): RedirectResponse
    {
        abort_unless(self::canDecide($request->user(), $item), 403);

        // Старата одлука не се губи — останува како коментар, за да се знае што било.
        DB::transaction(function () use ($item, $request) {
            if ($item->decision) {
                $item->comments()->create([
                    'user_id' => $request->user()->id,
                    'body'    => 'Отворено одново. Претходна одлука ('.($item->deciderName() ?? '—').', '.$item->decided_at?->format('d.m.Y').'): '.$item->decision,
                ]);
            }
            $item->update(['status' => 'open', 'decision' => null, 'decided_by' => null, 'decided_by_name' => null, 'decided_at' => null]);
        });

        return redirect()->to(route('projekt').'#stavka-'.$item->id)->with('ok', 'Прашањето е отворено одново.');
    }

    /** Само главниот администратор — пристап за сметководителката и другите. */
    public function storeUser(Request $request, SaveUser $save): RedirectResponse
    {
        abort_unless($request->user()->isSuper(), 403);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'side'     => ['required', Rule::in(array_keys(ProjectItem::SIDES))],
        ], [], ['name' => 'име', 'email' => 'е-пошта', 'password' => 'лозинка']);

        $user = $save->run(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'is_super' => false, 'is_active' => true]);
        $user->forceFill(['project_side' => $data['side']])->save();

        return redirect()->to(route('projekt').'#korisnici')->with('ok', 'Корисникот „'.$user->name.'“ е создаден. Лозинката кажете му ја лично, не по е-пошта.');
    }

    /**
     * JSON за разговорот со Claude: `php artisan projekt:povleci` го зема
     * од серверот за да се прочита што одговорила сметководителката.
     * Клучот е во заглавие (не во адреса), а без PROJEKT_KEY рутата не постои.
     */
    public function export(Request $request): JsonResponse
    {
        $key = (string) config('projekt.key');
        abort_if($key === '' || ! hash_equals($key, (string) $request->header('X-Projekt-Key')), 404);

        $items = ProjectItem::with('comments.user', 'decider', 'creator')->orderBy('side')->orderBy('sort')->orderBy('id')->get();

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'items'       => $items->map(fn (ProjectItem $i) => [
                'id' => $i->id, 'key' => $i->key, 'side' => $i->side, 'kind' => $i->kind, 'phase' => $i->phase,
                'title' => $i->title, 'body' => $i->body, 'status' => $i->status,
                'decision' => $i->decision, 'decided_by' => $i->deciderName(), 'decided_at' => $i->decided_at?->toIso8601String(),
                'created_by' => $i->creator?->name,
                'comments' => $i->comments->map(fn ($c) => ['by' => $c->user?->name, 'at' => $c->created_at->toIso8601String(), 'body' => $c->body])->all(),
            ])->all(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // ── Помошни ─────────────────────────────────────────────────────────────

    public static function sideOf(User $user): ?string
    {
        return $user->project_side ?? ($user->isSuper() ? 'owner' : null);
    }

    public static function canDecide(User $user, ProjectItem $item): bool
    {
        return $user->isSuper() || self::sideOf($user) === $item->side;
    }

    /** @return array{0: string, 1: array<int, array{id: string, title: string}>} */
    private function plan(): array
    {
        $md = (string) file_get_contents(resource_path('projekt/plan.md'));

        preg_match_all('/^## (.+?) \{#([a-z0-9-]+)\}\s*$/mu', $md, $m, PREG_SET_ORDER);
        $toc = array_map(fn ($x) => ['id' => $x[2], 'title' => $x[1]], $m);

        $html = Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false], [new AttributesExtension]);

        return [$html, $toc];
    }
}
