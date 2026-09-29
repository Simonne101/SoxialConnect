<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Inscription (docs/security.md §3). Le mot de passe est haché en Argon2id (HASH_DRIVER)
     * par le cast « hashed » du modèle ; seuls nom, e-mail et mot de passe sont acceptés.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $input['name'] = trim((string) ($input['name'] ?? ''));

        Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ], [
            'email.unique' => 'Impossible de créer un compte avec cette adresse e-mail.',
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
