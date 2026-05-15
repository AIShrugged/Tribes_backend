<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Required sections per Issue type
    |--------------------------------------------------------------------------
    |
    | Keys are section identifiers used by IssueContentValidator. The order is
    | preserved when rendering "what is missing" in notifications.
    |
    | The wildcard '*' applies to every Issue type not explicitly listed
    | (epic intentionally omits 'dod' — epics are open-ended objectives).
    |
    */
    'sections_per_type' => [
        'epic' => ['context', 'items'],
        '*'    => ['context', 'items', 'dod'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Heading aliases (case-insensitive)
    |--------------------------------------------------------------------------
    |
    | LLM output may use either Russian or English headings. The validator
    | matches any alias (case-insensitive, optional trailing ':' and ' ').
    |
    */
    'heading_aliases' => [
        'context' => ['Контекст', 'Context'],
        'items'   => ['Пункты', 'Steps'],
        'dod'     => ['Definition of done', 'Definition of Done', 'DOD', 'DoD'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum non-whitespace chars in a section body for it to count as filled
    |--------------------------------------------------------------------------
    */
    'non_empty_min_chars' => 3,

    /*
    |--------------------------------------------------------------------------
    | Placeholder tokens that disqualify a section (treated as if empty)
    |--------------------------------------------------------------------------
    */
    'placeholders' => ['TBD', '—', '???', '...'],

    /*
    |--------------------------------------------------------------------------
    | Human-readable section names for notification text (Russian)
    |--------------------------------------------------------------------------
    */
    'section_labels' => [
        'context' => 'Контекст',
        'items'   => 'Пункты',
        'dod'     => 'Definition of done',
    ],
];
