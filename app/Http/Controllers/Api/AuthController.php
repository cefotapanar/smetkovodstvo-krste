<?php

namespace App\Http\Controllers\Api;

use App\Models\Firm;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Најава на локалната апликација — токен, не сесија (истото како ЕРП-от).
 * Еден човек по компјутер, токенот без истек; одземање од Систем.
 */
class AuthController extends ApiController
{
    /** GET ping — без најава и без база: апликацијата го вика на неколку секунди. */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'server' => $this->serverInfo()]);
    }

    public function login(Request $request): JsonResponse
    {
        // Рачно на македонски: ова е единствениот екран пред влез во апликацијата.
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
        ], [
            'email.required'       => 'Внесете е-пошта.',
            'email.email'          => 'Е-поштата не е во исправен облик.',
            'password.required'    => 'Внесете лозинка.',
            'device_name.required' => 'Апликацијата не прати име на компјутерот.',
            'device_name.max'      => 'Името на компјутерот е предолго (најмногу 80 знаци).',
        ]);

        $user = User::where('email', $data['email'])->first();

        // Иста порака за непостоечка е-пошта и погрешна лозинка — инаку
        // одговорот кажува кои адреси постојат.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'error'   => 'invalid_credentials',
                'message' => 'Погрешна е-пошта или лозинка.',
            ], 401);
        }

        // Ова се кажува дури ПО точна лозинка, за да не открива ништо на непознат.
        if (! $user->is_active) {
            return response()->json([
                'error'   => 'inactive',
                'message' => 'Корисникот е деактивиран.',
            ], 403);
        }

        // Повторна најава од ист компјутер го брише стариот токен — инаку
        // списокот на уреди се полни со мртви редови.
        $user->tokens()->where('name', $data['device_name'])->delete();

        return response()->json([
            'token'  => $user->createToken($data['device_name'])->plainTextToken,
            'user'   => $this->profileOf($user),
            'firms'  => $this->firmsOf($user),
            'server' => $this->serverInfo(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user'   => $this->profileOf($user),
            'firms'  => $this->firmsOf($user),
            'server' => $this->serverInfo(),
        ]);
    }

    /** Го брише САМО токенот со кој е направен повикот. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Одјавени сте.']);
    }

    /** GET firm (со X-Firm) — потврда дека избраната фирма важи. */
    public function firm(Request $request): JsonResponse
    {
        return response()->json(['firm' => $this->firmFor(Firm::current(), $request->user())]);
    }
}
