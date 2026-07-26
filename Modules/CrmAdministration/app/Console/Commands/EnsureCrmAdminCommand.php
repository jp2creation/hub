<?php

namespace Modules\CrmAdministration\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\CrmAdministration\Services\AdminAccountService;

class EnsureCrmAdminCommand extends Command
{
    protected $signature = 'hub:admin
        {--email=admin@crm.jp2.fr : Email du compte administrateur}
        {--name=Administrateur : Nom affiche du compte administrateur}
        {--password-env= : Nom d\'une variable d\'environnement temporaire contenant le mot de passe}';

    protected $aliases = ['crm:admin'];

    protected $description = 'Create or update the HUB administrator account without storing the password in .env.example.';

    public function handle(AdminAccountService $admins): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $password = $this->password();

        if ($password === '') {
            $this->error('Mot de passe admin requis.');

            return self::FAILURE;
        }

        try {
            $admin = $admins->upsertDefaultAdmin($email, $name, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('Compte admin HUB pret : '.$admin->email);

        return self::SUCCESS;
    }

    private function password(): string
    {
        $passwordEnv = (string) $this->option('password-env');

        if ($passwordEnv !== '') {
            $value = getenv($passwordEnv);

            return is_string($value) ? $value : '';
        }

        $password = (string) $this->secret('Mot de passe admin');
        $confirmation = (string) $this->secret('Confirmer le mot de passe admin');

        if ($password !== $confirmation) {
            $this->error('Les mots de passe ne correspondent pas.');

            return '';
        }

        return $password;
    }
}
