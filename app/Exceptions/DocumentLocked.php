<?php

namespace App\Exceptions;

/**
 * Состојба што не дозволува дејство: прокнижен налог, второ сторно.
 * API → 409 `locked` (`bootstrap/app.php`). Порака за човек, на македонски.
 *
 * Посебна класа, а не гол RuntimeException: QueryException е исто
 * RuntimeException, и широко фаќање би ги прикажало грешките од базата
 * како „налогот е заклучен“ — порака што лаже (научено во ЕРП-от).
 */
class DocumentLocked extends \RuntimeException
{
}
