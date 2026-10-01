<?php

return [
    'plugin' => [
        'name' => 'Wizard',
        'description' => 'Plugin that allows you to easily implement and configure a wizard system of steps with forms and validations',
    ],
    'component' => [
        'name' => 'Wizard',
        'description' => 'Multi-page wizard with server-side gating, per-step validation and a privacy-first session store.',
    ],
    'properties' => [
        'steps' => [
            'title' => 'Steps',
            'description' => 'Step codes separated by | (e.g. step-1|step-2)',
        ],
        'titles' => [
            'title' => 'Titles',
            'description' => 'Step titles in the same order as the steps',
        ],
        'fields' => [
            'title' => 'Fields',
            'description' => 'Field names rendered as text inputs on every step (no validation rules are inferred)',
        ],
        'finish_url' => [
            'title' => 'Finish URL',
            'description' => 'URL where the finish action lands (default: /)',
        ],
        'ttl' => [
            'title' => 'Inactivity TTL (minutes)',
            'description' => 'Server-side sliding inactivity timeout; empty = global default (30), 0 = disabled',
        ],
        'wipe_on_back' => [
            'title' => 'Wipe data on back',
            'description' => 'Clear the data of later steps when going back to a previous one',
        ],
        'retain_data' => [
            'title' => 'Retain data',
            'description' => 'Keep data of later steps when going back (editable stepper)',
        ],
        'encrypted' => [
            'title' => 'Encrypt session data',
            'description' => 'Encrypt the wizard data persisted in the session',
        ],
        'keep_steps' => [
            'title' => 'Keep these steps on back',
            'description' => 'Step codes whose data survives going back (e.g. beneficiaries|review). Every other later step is still cleared. Ignored while "Retain data" is on.',
        ],
        'css' => [
            'title' => 'Load stylesheet',
            'description' => 'Load the built-in wizard stylesheet (default look). Turn off to use your theme styling; its rules live in @layer and the theme always wins.',
        ],
        'js' => [
            'title' => 'Load focus script',
            'description' => 'Load the built-in script that marks invalid fields with aria-invalid and focuses the first one on a failed AJAX submission, and that ignores repeated submissions of the same form while a request is in flight (without disabling the button). Turn off to implement your own focus behaviour with the same DOM events (ajaxPromise / ajaxDone / ajaxFail).',
        ],
    ],
    'errors' => [
        'form_summary' => 'Fix the following errors:',
        'steps_empty' => 'The wizard has no steps configured. Define the steps of the wizard (the "steps" property or the steps declared in the page).',
        'step_param_missing' => 'The page URL must declare an optional step parameter (e.g. "/:step?") for the wizard to work. Add ":step?" at the end of the page URL.',
        'step_code_empty' => 'A step of the wizard is declared without a code. Every step must declare a "code" (the URL slug of the step).',
        'field_name_empty' => 'A field of the wizard is declared without a name. Every field must declare a "name".',
        'step_not_found' => 'The step ":step" is not configured in this wizard. Use one of the declared step codes.',
        'keep_step_unknown' => 'The step ":step" is listed in "keepSteps" but is not a step of this wizard, so its data could never survive a back navigation. Fix the list or the code.',
    ],
    'nav' => [
        'steps' => 'Steps',
        'completed' => 'Completed',
        'current' => 'Current',
    ],
    'buttons' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'finish' => 'Finish',
    ],
    'fields' => [
        'select_default' => 'Select...',
    ],
];
