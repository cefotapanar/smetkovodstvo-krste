<?php

namespace App\Http\Controllers\Api;

use App\Actions\System\SaveFirm;
use App\Actions\System\SaveUser;
use App\Actions\System\SchemaStatus;
use App\Http\Requests\System\FirmRequest;
use App\Http\Requests\System\RoleRequest;
use App\Http\Requests\System\UserRequest;
use App\Models\Firm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Систем — фирми, улоги, корисници, состојба на базата.
 * Само главен администратор (`super` на рутите); не е по фирма.
 */
class SystemController extends ApiController
{
    // ── База ────────────────────────────────────────────────────────────────

    public function schema(SchemaStatus $schema): JsonResponse
    {
        $s = $schema->status();

        return response()->json([
            'pending'     => $s['pending'],
            'ahead'       => $s['ahead'],
            'applied'     => count($s['applied']),
            'head'        => $s['head'],
            'error'       => $s['error'],
            'guard_on'    => $s['guardOn'],
            'paused_till' => $s['pausedTill']?->toIso8601String(),
            'database'    => $s['database'],
        ]);
    }

    public function migrate(SchemaStatus $schema): JsonResponse
    {
        $r = $schema->migrate();

        // Друга миграција што тече не е грешка на барањето — 409, не 500.
        $status = $r['ok'] ? 200 : ($r['failed'] ? 500 : 409);

        return response()->json([
            'ok'      => $r['ok'],
            'message' => $r['message'],
            'output'  => $r['output'],
        ], $status);
    }

    public function pause(SchemaStatus $schema): JsonResponse
    {
        return response()->json(['message' => $schema->pause()]);
    }

    public function resume(SchemaStatus $schema): JsonResponse
    {
        return response()->json(['message' => $schema->resume()]);
    }

    // ── Фирми ───────────────────────────────────────────────────────────────

    public function firms(): JsonResponse
    {
        return response()->json([
            'data' => Firm::orderBy('name')->get()->map(fn (Firm $f) => $this->firmData($f))->all(),
        ]);
    }

    public function storeFirm(FirmRequest $request, SaveFirm $save): JsonResponse
    {
        return response()->json(['firm' => $this->firmData($save->run($request->validated()))], 201);
    }

    public function updateFirm(FirmRequest $request, Firm $firm, SaveFirm $save): JsonResponse
    {
        return response()->json(['firm' => $this->firmData($save->run($request->validated(), $firm))]);
    }

    // ── Улоги ───────────────────────────────────────────────────────────────

    public function roles(): JsonResponse
    {
        return response()->json([
            'data'     => Role::orderBy('name')->get()->map(fn (Role $r) => $this->roleData($r))->all(),
            'catalog'  => Role::GROUPS,
            'readonly' => Role::READONLY_KEYS,
        ]);
    }

    public function storeRole(RoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name'        => $request->validated('name'),
            'permissions' => array_values(array_unique($request->validated('permissions') ?? [])),
        ]);

        return response()->json(['role' => $this->roleData($role)], 201);
    }

    public function updateRole(RoleRequest $request, Role $role): JsonResponse
    {
        $role->name = $request->validated('name');
        // Непратени дозволи = не се менуваат; празна листа = се одземаат сите.
        if ($request->has('permissions')) {
            $role->permissions = array_values(array_unique($request->validated('permissions') ?? []));
        }
        $role->save();

        return response()->json(['role' => $this->roleData($role)]);
    }

    // ── Корисници ───────────────────────────────────────────────────────────

    public function users(): JsonResponse
    {
        return response()->json([
            'data' => User::with('firms')->orderBy('name')->get()->map(fn (User $u) => $this->userData($u))->all(),
        ]);
    }

    public function storeUser(UserRequest $request, SaveUser $save): JsonResponse
    {
        return response()->json(['user' => $this->userData($save->run($request->validated())->load('firms'))], 201);
    }

    public function updateUser(UserRequest $request, User $user, SaveUser $save): JsonResponse
    {
        return response()->json(['user' => $this->userData($save->run($request->validated(), $user)->load('firms'))]);
    }

    /** Одземање пристап на еден уред (останатите уреди на корисникот работат). */
    public function revokeDevice(User $user, int $token): JsonResponse
    {
        $deleted = $user->tokens()->whereKey($token)->delete();

        return $deleted
            ? response()->json(['message' => 'Пристапот на уредот е одземен.'])
            : response()->json(['error' => 'not_found', 'message' => 'Уредот не постои.'], 404);
    }

    /** @return array<string, mixed> */
    private function roleData(Role $role): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->knownPermissions()];
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return $this->profileOf($user) + [
            'is_active' => (bool) $user->is_active,
            'firms'     => $user->firms->map(fn (Firm $f) => [
                'firm_id' => $f->id,
                'name'    => $f->name,
                'role_id' => $f->pivot->role_id,
            ])->values()->all(),
            'devices'   => $user->tokens()->orderByDesc('last_used_at')->get()->map(fn ($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'last_ip'      => $t->last_ip,
                'app_version'  => $t->app_version,
            ])->all(),
        ];
    }
}
