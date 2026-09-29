<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de référence uniquement. Aucun compte de démonstration avec mot de passe connu :
 * en local, créez votre compte depuis l'interface (inscription).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SocialPlatformSeeder::class);
    }
}
