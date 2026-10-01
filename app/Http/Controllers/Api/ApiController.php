<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\User;

/**
 * Основа на API контролерите — облиците на одговорите што се повторуваат.
 * Еден облик за „фирма“ насекаде: апликацијата ја чита на едно место.
 */
abstract class ApiController extends Controller
{
    /** @return array<string, mixed> */
    protected function profileOf(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'is_super' => $user->isSuper(),
        ];
    }

    /** @return array<string, mixed> */
    protected function firmData(Firm $firm): array
    {
        return [
            'id'            => $firm->id,
            'name'          => $firm->name,
            'short_name'    => $firm->short_name,
            'tax_id'        => $firm->tax_id,
            'reg_no'        => $firm->reg_no,
            'activity_code' => $firm->activity_code,
            'size'          => $firm->size,
            'vat_period'    => $firm->vat_period,
            'address'       => $firm->address,
            'is_active'     => $firm->is_active,
        ];
    }

    /** Фирма гледана од корисникот — со неговата улога и права во неа. */
    protected function firmFor(Firm $firm, User $user): array
    {
        return $this->firmData($firm) + [
            'role'        => $user->roleLabelIn($firm),
            'permissions' => $user->permissionsIn($firm),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function firmsOf(User $user): array
    {
        return $user->accessibleFirms()->map(fn (Firm $f) => $this->firmFor($f, $user))->values()->all();
    }

    /** @return array<string, string> */
    protected function serverInfo(): array
    {
        return [
            'version'       => (string) config('version.number'),
            'api_version'   => 'v1',
            'local_app'     => (string) config('version.local_app'),
            'local_app_min' => (string) config('version.local_app_min'),
            'time'          => now()->toIso8601String(),
        ];
    }
}
