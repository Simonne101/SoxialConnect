<?php

// Données de démonstration — développement local uniquement (jamais en production).
return [

    // Compte (déjà inscrit via l'interface) qui recevra les comptes sociaux de démonstration :
    // php artisan db:seed --class=DemoSocialAccountsSeeder
    'seed_email' => env('DEMO_SEED_EMAIL'),

    // Environnements dans lesquels le seeder de démonstration peut s'exécuter.
    'allowed_environments' => ['local', 'testing'],
];
