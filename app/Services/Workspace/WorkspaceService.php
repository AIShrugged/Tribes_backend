<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class WorkspaceService
{
    private const DEFAULT_READ_LIMIT_BYTES = 64 * 1024;

    public function listContents(Workspace $workspace, string $path = ''): array
    {
        $prefix = $this->resolveStoragePath($workspace, $path);
        $disk = Storage::disk($workspace->storage_disk);
        $directories = collect($disk->directories($prefix))
            ->map(fn (string $directory): string => trim($directory, '/'))
            ->values();

        return [
            'path' => trim($this->normalizeRelativePath($path), '/'),
            'directories' => $directories
                ->map(fn (string $directory): string => $this->relativeFromRoot($workspace, $directory))
                ->values()
                ->all(),
            'files' => collect($disk->files($prefix))
                ->map(fn (string $file): string => trim($file, '/'))
                ->filter(fn (string $file): bool => ! $this->isDirectoryMarker($workspace, $file, $directories->all()))
                ->map(fn (string $file): array => [
                    'path' => $this->relativeFromRoot($workspace, $file),
                    'size' => $disk->size($file),
                    'last_modified' => $disk->lastModified($file),
                ])
                ->values()
                ->all(),
        ];
    }

    public function readFile(Workspace $workspace, string $path): string
    {
        return (string) Storage::disk($workspace->storage_disk)->get(
            $this->resolveStoragePath($workspace, $path)
        );
    }

    public function readFileWithLimit(Workspace $workspace, string $path, ?int $maxBytes = null): array
    {
        $relativePath = $this->normalizeRelativePath($path);
        $storagePath = $this->resolveStoragePath($workspace, $relativePath);
        $disk = Storage::disk($workspace->storage_disk);

        if (! $this->isFilePath($workspace, $storagePath)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Workspace file not found.');
        }

        $contents = (string) $disk->get($storagePath);
        $limit = max(1, $maxBytes ?? self::DEFAULT_READ_LIMIT_BYTES);
        $size = strlen($contents);
        $truncated = $size > $limit;

        return [
            'path' => $relativePath,
            'contents' => $truncated ? substr($contents, 0, $limit) : $contents,
            'size_bytes' => $size,
            'returned_bytes' => min($size, $limit),
            'truncated' => $truncated,
            'max_bytes' => $limit,
            'mime_type' => $disk->mimeType($storagePath),
            'last_modified' => $disk->lastModified($storagePath),
        ];
    }

    public function writeFile(Workspace $workspace, string $path, string $contents): void
    {
        Storage::disk($workspace->storage_disk)->put(
            $this->resolveStoragePath($workspace, $path),
            $contents
        );
    }

    public function delete(Workspace $workspace, string $path): bool
    {
        $disk = Storage::disk($workspace->storage_disk);
        $relative = $this->normalizeRelativePath($path);
        $storagePath = $this->resolveStoragePath($workspace, $relative);

        if ($relative === '') {
            return false;
        }

        if ($this->isFilePath($workspace, $storagePath)) {
            return $disk->delete($storagePath);
        }

        $files = $this->descendantFiles($workspace, $storagePath);
        if ($files !== []) {
            $deleted = $disk->delete($files);
            $disk->deleteDirectory($storagePath);

            return $deleted;
        }

        return false;
    }

    public function makeDirectory(Workspace $workspace, string $path): bool
    {
        $relative = $this->normalizeRelativePath($path);
        if ($relative === '') {
            return false;
        }

        return Storage::disk($workspace->storage_disk)->makeDirectory(
            $this->resolveStoragePath($workspace, $relative)
        );
    }

    public function searchFiles(Workspace $workspace, string $query, string $path = ''): array
    {
        $query = mb_strtolower(trim($query));
        $prefix = $this->resolveStoragePath($workspace, $path);
        $disk = Storage::disk($workspace->storage_disk);

        if ($query === '') {
            return [];
        }

        return collect($disk->allFiles($prefix))
            ->map(function (string $file) use ($workspace, $disk): array {
                $relative = $this->relativeFromRoot($workspace, $file);

                return [
                    'path' => $relative,
                    'name' => basename($relative),
                    'size' => $disk->size($file),
                    'last_modified' => $disk->lastModified($file),
                ];
            })
            ->filter(fn (array $file): bool => str_contains(mb_strtolower($file['path']), $query))
            ->values()
            ->all();
    }

    public function copy(Workspace $workspace, string $fromPath, string $toPath): bool
    {
        $disk = Storage::disk($workspace->storage_disk);
        $fromRelative = $this->normalizeRelativePath($fromPath);
        $toRelative = $this->normalizeRelativePath($toPath);
        $fromStoragePath = $this->resolveStoragePath($workspace, $fromRelative);
        $toStoragePath = $this->resolveStoragePath($workspace, $toRelative);

        if ($this->isFilePath($workspace, $fromStoragePath)) {
            return $disk->copy($fromStoragePath, $toStoragePath);
        }

        $files = $this->descendantFiles($workspace, $fromStoragePath);
        if ($files === []) {
            return false;
        }

        foreach ($files as $file) {
            $relative = ltrim((string) preg_replace('/^'.preg_quote(trim($fromStoragePath, '/'), '/').'\/?/', '', trim($file, '/')), '/');
            $target = trim($toStoragePath.'/'.$relative, '/');
            $disk->copy($file, $target);
        }

        return true;
    }

    public function move(Workspace $workspace, string $fromPath, string $toPath): bool
    {
        $disk = Storage::disk($workspace->storage_disk);
        $fromRelative = $this->normalizeRelativePath($fromPath);
        $toRelative = $this->normalizeRelativePath($toPath);
        $fromStoragePath = $this->resolveStoragePath($workspace, $fromRelative);
        $toStoragePath = $this->resolveStoragePath($workspace, $toRelative);

        if ($this->isFilePath($workspace, $fromStoragePath)) {
            return $disk->move($fromStoragePath, $toStoragePath);
        }

        $files = $this->descendantFiles($workspace, $fromStoragePath);
        if ($files === []) {
            return false;
        }

        foreach ($files as $file) {
            $relative = ltrim((string) preg_replace('/^'.preg_quote(trim($fromStoragePath, '/'), '/').'\/?/', '', trim($file, '/')), '/');
            $target = trim($toStoragePath.'/'.$relative, '/');
            $disk->copy($file, $target);
        }

        $disk->deleteDirectory($fromStoragePath);

        return true;
    }

    public function materializeWorkspaces(iterable $workspaceManifests, string $basePath): array
    {
        File::ensureDirectoryExists($basePath);
        @chmod($basePath, 0777);
        $materialized = [];

        foreach ($workspaceManifests as $manifest) {
            if (! is_array($manifest) || ! isset($manifest['id'], $manifest['root_prefix'], $manifest['storage_disk'])) {
                continue;
            }

            $localPath = rtrim($basePath, '/').'/'.$manifest['id'];
            File::ensureDirectoryExists($localPath);
            @chmod($localPath, 0777);
            $disk = Storage::disk((string) $manifest['storage_disk']);
            $rootPrefix = trim((string) $manifest['root_prefix'], '/');

            foreach ($disk->allFiles($rootPrefix) as $storagePath) {
                $relative = ltrim((string) preg_replace('/^'.preg_quote($rootPrefix, '/').'\/?/', '', trim($storagePath, '/')), '/');
                if ($relative === '') {
                    continue;
                }

                $target = $localPath.'/'.$relative;
                $directory = dirname($target);
                File::ensureDirectoryExists($directory);
                @chmod($directory, 0777);
                File::put($target, (string) $disk->get($storagePath));
                @chmod($target, 0666);
            }

            $materialized[] = [
                ...$manifest,
                'local_path' => $localPath,
                'sandbox_path' => '/workspace/synced-workspaces/'.$manifest['id'],
            ];
        }

        return $materialized;
    }

    public function syncBackMaterializedWorkspaces(iterable $workspaceManifests): void
    {
        foreach ($workspaceManifests as $manifest) {
            if (! is_array($manifest) || ! isset($manifest['id'], $manifest['root_prefix'], $manifest['storage_disk'], $manifest['local_path'])) {
                continue;
            }

            $permissions = is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [];
            if (($permissions['write'] ?? false) !== true) {
                continue;
            }

            $localPath = (string) $manifest['local_path'];
            if (! File::exists($localPath)) {
                continue;
            }

            $workspace = new Workspace([
                'id' => $manifest['id'],
                'root_prefix' => $manifest['root_prefix'],
                'storage_disk' => $manifest['storage_disk'],
            ]);

            $disk = Storage::disk((string) $manifest['storage_disk']);
            $rootPrefix = trim((string) $manifest['root_prefix'], '/');
            $localFiles = collect(File::allFiles($localPath))
                ->map(fn (\SplFileInfo $file): string => ltrim(str_replace('\\', '/', $file->getRelativePathname()), '/'))
                ->filter(fn (string $relative): bool => $relative !== '')
                ->values();

            foreach ($localFiles as $relative) {
                $disk->put(
                    $this->resolveStoragePath($workspace, $relative),
                    (string) File::get($localPath.'/'.$relative)
                );
            }

            if (($permissions['delete'] ?? false) === true) {
                $remoteFiles = collect($disk->allFiles($rootPrefix))
                    ->map(fn (string $storagePath): string => ltrim((string) preg_replace('/^'.preg_quote($rootPrefix, '/').'\/?/', '', trim($storagePath, '/')), '/'))
                    ->filter(fn (string $relative): bool => $relative !== '')
                    ->values();

                foreach ($remoteFiles->diff($localFiles) as $relative) {
                    $disk->delete($this->resolveStoragePath($workspace, $relative));
                }
            }
        }
    }

    public function resolveStoragePath(Workspace $workspace, string $path = ''): string
    {
        $root = trim($workspace->root_prefix, '/');
        $relative = trim($this->normalizeRelativePath($path), '/');

        if ($relative === '') {
            return $root;
        }

        return $root.'/'.$relative;
    }

    private function relativeFromRoot(Workspace $workspace, string $path): string
    {
        $root = trim($workspace->root_prefix, '/');
        $path = trim($path, '/');

        if ($path === $root) {
            return '';
        }

        return ltrim((string) preg_replace('/^'.preg_quote($root, '/').'\/?/', '', $path), '/');
    }

    private function normalizeRelativePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '.') {
            return '';
        }

        $parts = preg_split('/\/+/', str_replace('\\', '/', $trimmed)) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                throw new \InvalidArgumentException('Workspace path traversal is not allowed');
            }

            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    private function descendantFiles(Workspace $workspace, string $storagePrefix): array
    {
        $disk = Storage::disk($workspace->storage_disk);
        $storagePrefix = trim($storagePrefix, '/');

        return collect($disk->allFiles())
            ->map(fn (string $file): string => trim($file, '/'))
            ->filter(fn (string $file): bool => str_starts_with($file, $storagePrefix.'/'))
            ->values()
            ->all();
    }

    private function isFilePath(Workspace $workspace, string $storagePath): bool
    {
        $normalizedPath = trim($storagePath, '/');
        $disk = Storage::disk($workspace->storage_disk);

        return collect($disk->allFiles())
            ->map(fn (string $file): string => trim($file, '/'))
            ->contains($normalizedPath);
    }

    private function isDirectoryMarker(Workspace $workspace, string $storagePath, array $directories = []): bool
    {
        $normalizedPath = trim($storagePath, '/');

        if ($normalizedPath === '') {
            return true;
        }

        $normalizedDirectories = array_map(fn (string $directory): string => trim($directory, '/'), $directories);
        if (in_array($normalizedPath, $normalizedDirectories, true)) {
            return true;
        }

        return $this->relativeFromRoot($workspace, $normalizedPath) === '';
    }
}
