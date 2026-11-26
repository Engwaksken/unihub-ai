<?php

namespace App\Console\Commands;

use App\Gateway\AssertionSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateAssertionKeypair extends Command
{
    protected $signature = 'cms:assertion-keypair {path : Where to write the base64 Ed25519 secret key (outside the web root)} {--force : Overwrite an existing key file}';

    protected $description = 'Generate the Ed25519 key pair used to sign gateway actor assertions';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        $publicDir = realpath(public_path());
        $targetDir = realpath(dirname($path)) ?: dirname($path);
        if ($publicDir !== false && Str::startsWith(str_replace('\\', '/', $targetDir), str_replace('\\', '/', $publicDir))) {
            $this->error('Refusing to write the secret key inside the public web root.');

            return self::FAILURE;
        }

        if (file_exists($path) && ! $this->option('force')) {
            $this->error("{$path} already exists. Use --force to replace it (the gateway must then be given the new public key).");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            $this->error('Could not create directory '.dirname($path));

            return self::FAILURE;
        }

        [$secret, $public] = AssertionSigner::generateKeypair();

        $old = umask(0077);
        $written = file_put_contents($path, $secret.PHP_EOL, LOCK_EX);
        umask($old);
        @chmod($path, 0600);

        if ($written === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->info("Secret key written to {$path} (keep it private; set CMS_ASSERTION_SECRET_KEY_PATH to this path).");
        $this->line('Public key (install on the gateway as <GATEWAY_SECRETS_PATH>/cms_assertion.pub):');
        $this->line($public);

        return self::SUCCESS;
    }
}
