<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SecondBrainInstance;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;

/**
 * One-time migration of the single legacy second-brain (env TRIBESMCP_TOKEN +
 * the `second-brain-state` volume) into the new per-org model.
 *
 * Adopts the EXISTING token WITHOUT rotating it (so a running brain keeps its
 * /state continuity), stores it + the Claude credential encrypted on a
 * `second_brain_instances` row, and optionally copies the legacy volume into the
 * per-org volume. The legacy volume is never deleted (kept as a backup).
 */
class AdoptLegacySecondBrainCommand extends Command
{
    protected $signature = 'brain:adopt-legacy
        {--org= : Organization id; defaults to the one resolved from TRIBESMCP_TOKEN}
        {--from-volume= : Source volume name; defaults to auto-detected legacy volume}
        {--copy-volume : Copy the legacy /state volume into the per-org volume}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Adopt the legacy single-org second brain (token + state volume) into the per-org model';

    public function handle(): int
    {
        $plainToken = $this->readEnv('TRIBESMCP_TOKEN');
        if ($plainToken === '') {
            $this->error('TRIBESMCP_TOKEN is not set in the environment; nothing to adopt.');

            return self::FAILURE;
        }

        $pat = PersonalAccessToken::findToken($plainToken);
        if ($pat === null) {
            $this->error('TRIBESMCP_TOKEN does not match any personal access token (already rotated/revoked?).');

            return self::FAILURE;
        }

        $serviceUser = $pat->tokenable;
        $organization = $this->resolveOrganization($serviceUser);
        if ($organization === null) {
            return self::FAILURE;
        }

        // Claude credential from the legacy env (subscription preferred).
        $oauth = $this->readEnv('CLAUDE_CODE_OAUTH_TOKEN');
        $apiKey = $this->readEnv('ANTHROPIC_API_KEY');
        $authType = $oauth !== '' ? SecondBrainInstance::AUTH_OAUTH : SecondBrainInstance::AUTH_API_KEY;
        $authToken = $oauth !== '' ? $oauth : $apiKey;
        if ($authToken === '') {
            $this->error('Neither CLAUDE_CODE_OAUTH_TOKEN nor ANTHROPIC_API_KEY is set; cannot adopt a Claude credential.');

            return self::FAILURE;
        }

        $this->line("  Organization : #{$organization->id} {$organization->name}");
        $this->line("  Service user : #{$serviceUser->id} {$serviceUser->email}");
        $this->line('  Token        : reused as-is (NOT rotated)');
        $this->line('  Claude auth  : '.$authType);

        if (! $this->option('force') && ! $this->confirm('Adopt this legacy second brain into the per-org model?', true)) {
            return self::SUCCESS;
        }

        $instance = SecondBrainInstance::updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'service_user_id' => $serviceUser->id,
                'token_id' => $pat->getKey(),
                'token_ciphertext' => $plainToken,
                'claude_auth_type' => $authType,
                'claude_auth_ciphertext' => $authToken,
                'enabled' => true,
                'status' => SecondBrainInstance::STATUS_PENDING,
                'container_name' => SecondBrainInstance::containerNameFor($organization->id),
                'volume_name' => SecondBrainInstance::volumeNameFor($organization->id),
                'credentials_changed_at' => now(),
                'last_error' => null,
            ],
        );

        $this->info("Adopted into second_brain_instances #{$instance->id} (enabled, pending).");

        if ($this->option('copy-volume')) {
            if (! $this->copyVolume($organization->id)) {
                return self::FAILURE;
            }
        } else {
            $this->warn('Volume NOT copied. Re-run with --copy-volume, or start fresh state for this org.');
        }

        $this->newLine();
        $this->line('Next: start `brain-orchestrator` (it will launch second-brain-org'.$organization->id.').');
        $this->line('After verifying, remove the legacy container/volume and its compose declaration.');

        return self::SUCCESS;
    }

    private function resolveOrganization(mixed $serviceUser): ?Organization
    {
        if ($this->option('org') !== null) {
            $org = Organization::find((int) $this->option('org'));
            if ($org === null) {
                $this->error("Organization #{$this->option('org')} not found.");
            }

            return $org;
        }

        if ($serviceUser === null) {
            $this->error('The token has no owner; pass --org explicitly.');

            return null;
        }

        $orgIds = $serviceUser->organizations()->pluck('organizations.id');
        if ($orgIds->count() !== 1) {
            $this->error("The token user belongs to {$orgIds->count()} organizations; pass --org explicitly.");

            return null;
        }

        return Organization::find((int) $orgIds->first());
    }

    private function copyVolume(int $orgId): bool
    {
        $source = (string) ($this->option('from-volume') ?: $this->detectLegacyVolume());
        if ($source === '') {
            $this->error('Could not auto-detect the legacy volume; pass --from-volume=<name>.');

            return false;
        }

        $dest = SecondBrainInstance::volumeNameFor($orgId);
        $this->line("  Copying volume: {$source} -> {$dest}");

        // Stop the legacy singleton first so /state is not written concurrently.
        $this->docker(['docker', 'rm', '-f', 'second-brain']);

        if (! $this->docker(['docker', 'volume', 'create', $dest])['ok']) {
            $this->error("Failed to create volume {$dest}.");

            return false;
        }

        $copy = $this->docker([
            'docker', 'run', '--rm',
            '-v', $source.':/from:ro',
            '-v', $dest.':/to',
            'alpine', 'sh', '-c', 'cp -a /from/. /to/',
        ], 600);

        if (! $copy['ok']) {
            $this->error('Volume copy failed: '.$copy['stderr']);

            return false;
        }

        $this->info("Volume copied. Legacy volume '{$source}' kept as a backup (delete manually after verifying).");

        return true;
    }

    private function detectLegacyVolume(): string
    {
        $result = $this->docker(['docker', 'volume', 'ls', '--format', '{{.Name}}']);
        if (! $result['ok']) {
            return '';
        }

        $candidates = [];
        foreach (explode("\n", $result['stdout']) as $name) {
            $name = trim($name);
            // Legacy volume ends with "second-brain-state" but is NOT a per-org one.
            if ($name !== '' && str_ends_with($name, 'second-brain-state') && ! preg_match('/second-brain-state-org\d+$/', $name)) {
                $candidates[] = $name;
            }
        }

        // Prefer an exact match, else the first candidate.
        if (in_array('second-brain-state', $candidates, true)) {
            return 'second-brain-state';
        }

        return $candidates[0] ?? '';
    }

    private function readEnv(string $key): string
    {
        $value = env($key);
        if ($value === null || $value === false || $value === '') {
            $value = getenv($key);
        }

        return trim((string) ($value === false ? '' : $value));
    }

    /** @return array{ok: bool, stdout: string, stderr: string} */
    private function docker(array $args, int $timeout = 120): array
    {
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }
}
