<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\StaffDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BootstrapOwner extends Command
{
    protected $signature = 'cms:bootstrap-owner {email : E-mail address of the first owner} {name : Display name}';

    protected $description = 'Create the first owner account (one time only) and print a single-use set-password link';

    public function handle(StaffDirectory $staff): int
    {
        if (User::ownerExists()) {
            $this->error('An owner already exists. This command can only be used once; owners invite further staff from the portal.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $name = (string) $this->argument('name');

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        [, $token] = $staff->invite($email, $name, [Role::Owner], [], invitedBy: null, notify: false);

        $hours = (int) config('cms.invitation_ttl_hours', 24);
        $this->info('Owner account created. Open this single-use link to set the password (expires in '.$hours.' hours):');
        $this->line(route('invitations.show', ['token' => $token]));
        $this->newLine();
        $this->line('After setting the password, the owner must enrol an authenticator app before using the portal.');

        return self::SUCCESS;
    }
}
