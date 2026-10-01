<?php

namespace App\Actions\System;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Нов корисник или измена — заедно со фирмите и улогата во секоја.
 *
 * Две правила што не смеат да се изгубат:
 *  1. Системот никогаш не останува без активен главен администратор —
 *     инаку нема кој да го врати пристапот (нема SSH, нема phpMyAdmin во
 *     рацете на сметководителот).
 *  2. Деактивирање или нова лозинка ги бришат токените: одземениот пристап
 *     мора да важи веднаш, не кога ќе се рестартира апликацијата.
 */
class SaveUser
{
    public function run(array $data, ?User $user = null): User
    {
        return DB::transaction(function () use ($data, $user) {
            $isNew = $user === null;
            $user ??= new User(['is_super' => false, 'is_active' => true]);

            $wasActive = (bool) ($user->is_active ?? true);
            $passwordChanged = false;

            foreach (['name', 'email', 'is_super', 'is_active'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $user->{$field} = $data[$field];
                }
            }

            if (($data['password'] ?? '') !== '') {
                $user->password = $data['password']; // cast `hashed`
                $passwordChanged = ! $isNew;
            }

            $user->save();

            // Проверката е ПО запишувањето, во трансакцијата: така ја фаќа и
            // измената што го тргнува последниот администратор, без разлика кој ја прави.
            if (! User::where('is_super', true)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_super' => 'Мора да остане барем еден активен главен администратор.',
                ]);
            }

            if (array_key_exists('firms', $data) && $data['firms'] !== null) {
                $sync = [];
                foreach ($data['firms'] as $row) {
                    $sync[(int) $row['firm_id']] = ['role_id' => $row['role_id'] ?? null];
                }
                $user->firms()->sync($sync);
            }

            if (! $isNew && (($wasActive && ! $user->is_active) || $passwordChanged)) {
                $user->tokens()->delete();
            }

            return $user;
        });
    }
}
