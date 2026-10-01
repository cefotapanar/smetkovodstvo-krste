<?php

namespace App\Actions\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Период на извештај — секогаш во рамки на ЕДНА година.
 *
 * Почетната состојба на годината доаѓа од налогот ПС; преку граница на
 * година салдата би се собрале двапати (крајот на старата + ПС на новата).
 */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $year,
    ) {
    }

    public static function fromInput(?string $from, ?string $to): self
    {
        try {
            $t = $to ? CarbonImmutable::parse($to) : null;
            $f = $from ? CarbonImmutable::parse($from) : null;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => 'Датумот не е во исправен облик.']);
        }

        $year = (int) ($f ?? $t ?? CarbonImmutable::today())->format('Y');
        $f ??= CarbonImmutable::create($year, 1, 1);
        $t ??= $year === (int) date('Y') ? CarbonImmutable::today() : CarbonImmutable::create($year, 12, 31);

        if ((int) $t->format('Y') !== $year) {
            throw ValidationException::withMessages(['to' => 'Периодот мора да е во рамки на една година.']);
        }
        if ($t->lt($f)) {
            throw ValidationException::withMessages(['to' => '„До“ е пред „од“.']);
        }

        return new self($f->startOfDay(), $t->startOfDay(), $year);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'year' => $this->year];
    }
}
