<?php

return [
    'plugin' => [
        'name' => 'Wizard',
        'description' => 'Plugin que permite implementar y configurar fácilmente un sistema wizard de pasos con formularios y validaciones',
    ],
    'component' => [
        'name' => 'Wizard',
        'description' => 'Wizard multi-página con bloqueo server-side, validación por paso y almacén de sesión privacy-first.',
    ],
    'properties' => [
        'steps' => [
            'title' => 'Pasos',
            'description' => 'Códigos de paso separados por | (ej. step-1|step-2)',
        ],
        'titles' => [
            'title' => 'Títulos',
            'description' => 'Títulos de los pasos en el mismo orden que los pasos',
        ],
        'fields' => [
            'title' => 'Campos',
            'description' => 'Nombres de campos renderizados como inputs de texto en cada paso (no se infieren reglas de validación)',
        ],
        'finish_url' => [
            'title' => 'URL de finalización',
            'description' => 'URL donde aterriza la acción de finalizar (por defecto: /)',
        ],
        'ttl' => [
            'title' => 'TTL de inactividad (minutos)',
            'description' => 'Timeout de inactividad deslizante server-side; vacío = default global (30), 0 = deshabilitado',
        ],
        'wipe_on_back' => [
            'title' => 'Borrar datos al retroceder',
            'description' => 'Limpia los datos de pasos posteriores al retroceder a uno previo',
        ],
        'retain_data' => [
            'title' => 'Retener datos',
            'description' => 'Conserva los datos de pasos posteriores al retroceder (stepper editable)',
        ],
        'encrypted' => [
            'title' => 'Cifrar datos de sesión',
            'description' => 'Cifra los datos del wizard persistidos en la sesión',
        ],
        'keep_steps' => [
            'title' => 'Conservar estos pasos al retroceder',
            'description' => 'Códigos de paso cuyos datos sobreviven al retroceder (ej. beneficiarios|revision). Los demás pasos posteriores se siguen borrando. Sin efecto mientras «Conservar datos» esté activo.',
        ],
        'css' => [
            'title' => 'Cargar hoja de estilos',
            'description' => 'Carga la hoja de estilos del wizard (look por defecto). Desactívala para usar el estilo de tu tema; sus reglas van en @layer y el tema siempre prevalece.',
        ],
        'js' => [
            'title' => 'Cargar script de foco',
            'description' => 'Carga el script que marca los campos inválidos con aria-invalid y enfoca el primero cuando falla la validación AJAX, y que ignora los envíos repetidos del mismo formulario mientras hay una petición en curso (sin deshabilitar el botón). Desactívalo para implementar tu propio comportamiento de foco con los mismos eventos DOM (ajaxPromise / ajaxDone / ajaxFail).',
        ],
    ],
    'errors' => [
        'form_summary' => 'Corrige estos errores:',
        'steps_empty' => 'El wizard no tiene pasos configurados. Define los pasos del wizard (la propiedad "steps" o los pasos declarados en la página).',
        'step_param_missing' => 'La URL de la página debe declarar un parámetro de paso opcional (ej. "/:step?") para que el wizard funcione. Agrega ":step?" al final de la URL de la página.',
        'step_code_empty' => 'Un paso del wizard se declaró sin código. Cada paso debe declarar un "code" (el slug de URL del paso).',
        'field_name_empty' => 'Un campo del wizard se declaró sin nombre. Cada campo debe declarar un "name".',
        'step_not_found' => 'El paso ":step" no está configurado en este wizard. Usa uno de los códigos de paso declarados.',
        'keep_step_unknown' => 'El paso ":step" está en la lista «keepSteps» pero no es un paso de este wizard, así que sus datos nunca podrían sobrevivir a un retroceso. Corrige la lista o el código.',
    ],
    'nav' => [
        'steps' => 'Pasos',
        'completed' => 'Completado',
        'current' => 'Actual',
    ],
    'buttons' => [
        'previous' => 'Anterior',
        'next' => 'Siguiente',
        'finish' => 'Finalizar',
    ],
    'fields' => [
        'select_default' => 'Seleccionar...',
    ],
];
