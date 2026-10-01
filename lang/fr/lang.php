<?php

return [
    'plugin' => [
        'name' => 'Wizard',
        'description' => 'Plugin qui vous permet d\'implémenter et de configurer facilement un formulaire en étapes avec validations',
    ],
    'component' => [
        'name' => 'Wizard',
        'description' => 'Assistant multi-pages avec verrouillage serveur, validation par étape et stockage de session axé sur la confidentialité.',
    ],
    'properties' => [
        'steps' => [
            'title' => 'Étapes',
            'description' => 'Codes d\'étape séparés par | (ex. step-1|step-2)',
        ],
        'titles' => [
            'title' => 'Titres',
            'description' => 'Titres des étapes dans le même ordre que les étapes',
        ],
        'fields' => [
            'title' => 'Champs',
            'description' => 'Noms de champs rendus comme des entrées de texte à chaque étape (aucune règle de validation n\'est déduite)',
        ],
        'finish_url' => [
            'title' => 'URL de fin',
            'description' => 'URL de l\'action de fin (défaut : /)',
        ],
        'ttl' => [
            'title' => 'TTL d\'inactivité (minutes)',
            'description' => 'Délai d\'inactivité glissant côté serveur ; vide = défaut global (30), 0 = désactivé',
        ],
        'wipe_on_back' => [
            'title' => 'Effacer les données au retour',
            'description' => 'Efface les données des étapes suivantes au retour vers une étape précédente',
        ],
        'retain_data' => [
            'title' => 'Conserver les données',
            'description' => 'Conserve les données des étapes suivantes au retour (stepper éditable)',
        ],
        'encrypted' => [
            'title' => 'Chiffrer les données de session',
            'description' => 'Chiffre les données du wizard persistées en session',
        ],
        'keep_steps' => [
            'title' => 'Conserver ces étapes au retour',
            'description' => 'Codes des étapes dont les données survivent au retour (ex. beneficiaires|recapitulatif). Toutes les autres étapes suivantes sont toujours effacées. Sans effet tant que « Conserver les données » est actif.',
        ],
        'css' => [
            'title' => 'Charger la feuille de styles',
            'description' => 'Charge la feuille de styles du wizard (apparence par défaut). Désactivez-la pour utiliser le style de votre thème ; ses règles sont dans @layer et le thème a toujours priorité.',
        ],
        'js' => [
            'title' => 'Charger le script de focus',
            'description' => 'Charge le script qui marque les champs invalides avec aria-invalid et place le focus sur le premier en cas d\'échec de validation AJAX, et qui ignore les soumissions répétées du même formulaire tant qu\'une requête est en cours (sans désactiver le bouton). Désactivez-le pour implémenter votre propre comportement de focus avec les mêmes événements DOM (ajaxPromise / ajaxDone / ajaxFail).',
        ],
    ],
    'errors' => [
        'form_summary' => 'Corrigez les erreurs suivantes :',
        'steps_empty' => 'Le wizard n\'a aucune étape configurée. Définissez les étapes du wizard (la propriété « steps » ou les étapes déclarées dans la page).',
        'step_param_missing' => 'L\'URL de la page doit déclarer un paramètre d\'étape optionnel (ex. « /:step? ») pour que le wizard fonctionne. Ajoutez « :step? » à la fin de l\'URL de la page.',
        'step_code_empty' => 'Une étape du wizard est déclarée sans code. Chaque étape doit déclarer un « code » (le slug d\'URL de l\'étape).',
        'field_name_empty' => 'Un champ du wizard est déclaré sans nom. Chaque champ doit déclarer un « name ».',
        'step_not_found' => 'L\'étape « :step » n\'est pas configurée dans ce wizard. Utilisez l\'un des codes d\'étape déclarés.',
        'keep_step_unknown' => 'L\'étape « :step » figure dans « keepSteps » mais n\'est pas une étape de ce wizard : ses données ne pourraient jamais survivre à un retour. Corrigez la liste ou le code.',
    ],
    'nav' => [
        'steps' => 'Étapes',
        'completed' => 'Terminé',
        'current' => 'Actuel',
    ],
    'buttons' => [
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'finish' => 'Terminer',
    ],
    'fields' => [
        'select_default' => 'Sélectionner...',
    ],
];
