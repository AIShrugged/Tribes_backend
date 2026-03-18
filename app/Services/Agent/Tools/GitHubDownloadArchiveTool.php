<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class GitHubDownloadArchiveTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
        private readonly string $workspacePath,
        private readonly bool $preserveDependencies = false,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_download_archive';
    }

    public function getDescription(): string
    {
        return 'Download and extract a GitHub repository archive into the current sandbox workspace so local commands can install dependencies and run tests.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner' => [
                    'type' => 'string',
                    'description' => 'GitHub repository owner or organization.',
                ],
                'repo' => [
                    'type' => 'string',
                    'description' => 'GitHub repository name.',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Optional branch, tag, or commit SHA. If omitted, the tool prefers dev, then develop, then the default branch.',
                ],
                'destination' => [
                    'type' => 'string',
                    'description' => 'Relative workspace directory where the repository should be extracted. Defaults to repo.',
                ],
            ],
            'required' => ['owner', 'repo'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $ref = trim((string) ($parameters['ref'] ?? ''));
        $destination = trim((string) ($parameters['destination'] ?? 'repo'));

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required'];
        }

        try {
            $resolvedRef = $this->resolveRef($owner, $repo, $ref !== '' ? $ref : null);
            $destinationPath = $this->resolveDestinationPath($destination);
            $archivePath = tempnam(sys_get_temp_dir(), 'gh-archive-');
            $extractPath = sys_get_temp_dir().'/gh-archive-'.bin2hex(random_bytes(8));

            if ($archivePath === false) {
                throw new \RuntimeException('Unable to allocate temporary archive file');
            }

            File::ensureDirectoryExists($extractPath);
            File::ensureDirectoryExists(dirname($destinationPath));

            $this->client->downloadArchive($owner, $repo, $resolvedRef['ref'], $archivePath);
            $this->extractArchive($archivePath, $extractPath);

            $children = collect(File::directories($extractPath));
            $root = $children->count() === 1 ? $children->first() : $extractPath;

            if (! is_string($root) || ! File::exists($root)) {
                throw new \RuntimeException('Extracted archive is empty');
            }

            if ($this->preserveDependencies && File::isDirectory($destinationPath)) {
                $this->mergeExtractedRepository($root, $destinationPath);
            } else {
                File::deleteDirectory($destinationPath);

                if (! File::copyDirectory($root, $destinationPath)) {
                    throw new \RuntimeException('Unable to copy extracted repository into workspace');
                }
            }

            $this->makeWorkspaceWritable($destinationPath);

            File::delete($archivePath);
            File::deleteDirectory($extractPath);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'ref' => $resolvedRef['ref'],
            'commit_sha' => $resolvedRef['commit_sha'],
            'workspace_path' => '/workspace/'.trim($destination, '/'),
            'destination' => trim($destination, '/'),
            'preserved_dependencies' => $this->preserveDependencies,
        ];
    }

    private function resolveRef(string $owner, string $repo, ?string $requestedRef): array
    {
        if ($requestedRef !== null && $requestedRef !== '') {
            try {
                $branchInfo = $this->client->getBranch($owner, $repo, $requestedRef);

                return [
                    'ref' => $requestedRef,
                    'commit_sha' => (string) (($branchInfo['commit'] ?? [])['sha'] ?? ''),
                ];
            } catch (\Throwable) {
                return [
                    'ref' => $requestedRef,
                    'commit_sha' => '',
                ];
            }
        }

        $repository = $this->client->getRepository($owner, $repo);
        $defaultBranch = (string) ($repository['default_branch'] ?? '');
        $branches = $this->client->listBranches($owner, $repo);
        $available = array_values(array_filter(array_map(
            static fn (array $item) => is_string($item['name'] ?? null) ? $item['name'] : null,
            array_values(array_filter($branches, 'is_array'))
        )));

        $resolvedBranch = collect(['dev', 'develop', $defaultBranch])
            ->filter(fn ($candidate) => is_string($candidate) && $candidate !== '')
            ->first(fn ($candidate) => in_array($candidate, $available, true))
            ?? $defaultBranch;

        if (! is_string($resolvedBranch) || $resolvedBranch === '') {
            throw new \RuntimeException('Unable to resolve repository branch for archive download');
        }

        $branchInfo = $this->client->getBranch($owner, $repo, $resolvedBranch);

        return [
            'ref' => $resolvedBranch,
            'commit_sha' => (string) (($branchInfo['commit'] ?? [])['sha'] ?? ''),
        ];
    }

    private function resolveDestinationPath(string $destination): string
    {
        $trimmed = trim($destination, '/');
        if ($trimmed === '') {
            $trimmed = 'repo';
        }

        if (str_contains($trimmed, '..')) {
            throw new \RuntimeException('Destination must stay inside the sandbox workspace');
        }

        $path = $this->workspacePath.'/'.$trimmed;
        $workspaceReal = realpath($this->workspacePath);
        if ($workspaceReal === false) {
            throw new \RuntimeException('Sandbox workspace is unavailable');
        }

        $parent = realpath(dirname($path));
        if ($parent !== false && ! str_starts_with($parent, $workspaceReal)) {
            throw new \RuntimeException('Destination escapes the sandbox workspace');
        }

        return $path;
    }

    private function extractArchive(string $archivePath, string $extractPath): void
    {
        $process = new Process(['unzip', '-q', $archivePath, '-d', $extractPath]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to extract downloaded archive');
        }
    }

    private function makeWorkspaceWritable(string $path): void
    {
        if (! File::exists($path)) {
            return;
        }

        @chmod($path, 0777);

        foreach (File::allFiles($path) as $file) {
            @chmod($file->getPathname(), 0666);
        }

        foreach (File::directories($path) as $directory) {
            $this->makeWorkspaceWritable($directory);
        }
    }

    private function mergeExtractedRepository(string $sourcePath, string $destinationPath): void
    {
        File::ensureDirectoryExists($destinationPath);

        foreach (File::directories($sourcePath) as $directory) {
            $name = basename($directory);
            if (in_array($name, ['vendor', 'node_modules', '.git'], true)) {
                continue;
            }

            $targetDirectory = $destinationPath.'/'.$name;
            $this->mergeExtractedRepository($directory, $targetDirectory);
        }

        foreach (File::files($sourcePath) as $file) {
            $targetFile = $destinationPath.'/'.$file->getFilename();
            File::ensureDirectoryExists(dirname($targetFile));
            File::copy($file->getPathname(), $targetFile);
            @chmod($targetFile, 0666);
        }

        @chmod($destinationPath, 0777);
    }
}
