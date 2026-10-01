<?php

/*
 * Македонски пораки за валидација (kirilica).
 * Сними го како: lang/mk/validation.php
 * (ако ја нема папката lang, прво: php artisan lang:publish)
 *
 * Имињата на полињата (:attribute) доаѓаат од attributes() во секој FormRequest.
 */

return [
    'required' => 'Полето :attribute е задолжително.',
    'string'   => 'Полето :attribute мора да биде текст.',
    'email'    => 'Полето :attribute мора да биде валидна е-пошта.',
    'boolean'  => 'Полето :attribute мора да биде точно или неточно.',
    'date'     => 'Полето :attribute мора да биде валиден датум.',
    'numeric'  => 'Полето :attribute мора да биде број.',
    'integer'  => 'Полето :attribute мора да биде цел број.',
    'in'       => 'Избраната вредност за :attribute е невалидна.',
    'unique'   => 'Вредноста за :attribute веќе постои.',
    'exists'   => 'Избраната вредност за :attribute не постои.',
    'required_if'          => 'Полето :attribute е задолжително.',
    'required_if_accepted' => 'Полето :attribute е задолжително.',
    'required_with'        => 'Полето :attribute е задолжително.',
    'required_without'     => 'Полето :attribute е задолжително.',

    'max' => [
        'string'  => 'Полето :attribute не смее да биде подолго од :max знаци.',
        'numeric' => 'Полето :attribute не смее да биде поголемо од :max.',
    ],
    'min' => [
        'string'  => 'Полето :attribute мора да има најмалку :min знаци.',
        'numeric' => 'Полето :attribute мора да биде најмалку :min.',
    ],

    // Имиња на полиња по форма се поставуваат преку attributes() во FormRequest,
    // па тука ги оставаме празни.
    'custom'     => [],
    'attributes' => [],
];
