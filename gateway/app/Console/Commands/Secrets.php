<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Secrets as SecretStore;
use App\Support\Base32;
use Illuminate\Console\Command;

/** Provisioning of secrets that live outside the DB and the repository. */
class Secrets extends Command
{
    protected $signature = 'gateway:secrets
        {action : init | mgmt-token | rotate-payload-key | cms-key}
        {--replace : mgmt-token: invalidate previous tokens instead of keeping one for overlap}
        {--public-key= : cms-key: base64 Ed25519 public key printed by cms:assertion-keypair}';

    protected $description = 'Initialise or rotate gateway secrets';

    public function handle(SecretStore $secrets, AuditLogger $audit): int
    {
        switch ($this->argument('action')) {
            case 'init':
                $created = $secrets->initialise();
                $this->info($created ? 'Created: '.implode(', ', $created) : 'All secrets already present; nothing changed.');
                $this->line('Secrets directory: '.$secrets->path());

                return self::SUCCESS;

            case 'mgmt-token':
                $token = 'uhm_'.Base32::base64url(random_bytes(32));
                $secrets->appendMgmtTokenDigest($secrets->hmac($token), (bool) $this->option('replace'));
                $audit->record('system:cli', 'mgmt_token.issued', 'gateway', null, null, ['replace' => (bool) $this->option('replace')]);
                $this->warn('Management token (shown ONCE; set GATEWAY_MGMT_TOKEN in the CMS environment):');
                $this->line($token);

                return self::SUCCESS;

            case 'rotate-payload-key':
                $file = $secrets->addPayloadKey();
                $audit->record('system:cli', 'payload_key.rotated', 'gateway', null, null, ['file' => basename($file)]);
                $this->info("New payload key {$file}; older keys stay for decrypting existing rows until they expire.");

                return self::SUCCESS;

            case 'cms-key':
                $pk = base64_decode((string) $this->option('public-key'), true);
                if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $this->error('--public-key must be a base64 32-byte Ed25519 public key');

                    return self::INVALID;
                }
                $secrets->writeSecretFile('cms_assertion.pub', base64_encode($pk), true);
                $audit->record('system:cli', 'cms_public_key.installed', 'gateway', null, null, ['fingerprint' => substr(hash('sha256', $pk), 0, 16)]);
                $this->info('CMS public key installed.');

                return self::SUCCESS;
        }
        $this->error('Unknown action');

        return self::INVALID;
    }
}
