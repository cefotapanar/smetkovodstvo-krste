<?php

namespace App\Http\Middleware;

use App\Models\Firm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ја зема фирмата од заглавието `X-Firm` и ја става во контејнерот
 * (`Firm::current()`).
 *
 * Нема „стандардна фирма“ ни кога корисникот има само една: денес има една,
 * утре две, а налог запишан во погрешна фирма е најскапата грешка во книгите
 * — се открива дури на бруто билансот, месеци подоцна.
 *
 * Непостоечка фирма и фирма без пристап враќаат ИСТ одговор, за одговорот да
 * не кажува кои фирми постојат.
 */
class ResolveFirm
{
    public function handle(Request $request, Closure $next): Response
    {
        // Во тестовите (и при повеќе барања во ист процес) контејнерот е ист;
        // фирмата од претходното барање не смее да остане да важи.
        app()->forgetInstance(Firm::CONTAINER_KEY);

        $raw = trim((string) $request->header('X-Firm'));
        if ($raw === '' || ! ctype_digit($raw)) {
            return response()->json([
                'error'   => 'firm_required',
                'message' => 'Не е избрана фирма.',
            ], 400);
        }

        $firm = Firm::find((int) $raw);
        if (! $firm || ! $request->user()?->canAccessFirm($firm)) {
            return response()->json([
                'error'   => 'firm_forbidden',
                'message' => 'Немате пристап до оваа фирма.',
            ], 403);
        }

        app()->instance(Firm::CONTAINER_KEY, $firm);

        return $next($request);
    }
}
