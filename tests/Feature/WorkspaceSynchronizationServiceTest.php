<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceSynchronizationServiceTest extends TestCase
{
    public function test_materialize_and_sync_back_workspaces_round_trip_files(): void
    {
        Storage::fake('local');

        $service = app(WorkspaceService::class);
        $rootPrefix = 'workspaces/orgs/1/shared/shared';

        Storage::disk('local')->put($rootPrefix.'/docs/a.txt', 'alpha');
        Storage::disk('local')->put($rootPrefix.'/docs/remove.txt', 'remove-me');

        $basePath = storage_path('app/private/workspace-sync-test');
        File::deleteDirectory($basePath);

        $manifest = [[
            'id' => 'workspace-1',
            'root_prefix' => $rootPrefix,
            'storage_disk' => 'local',
            'permissions' => [
                'write' => true,
                'delete' => true,
            ],
        ]];

        $materialized = $service->materializeWorkspaces($manifest, $basePath);

        $localRoot = $basePath.'/workspace-1';
        $this->assertFileExists($localRoot.'/docs/a.txt');
        $this->assertSame('alpha', File::get($localRoot.'/docs/a.txt'));

        File::put($localRoot.'/docs/a.txt', 'alpha-updated');
        File::put($localRoot.'/docs/b.txt', 'bravo');
        File::delete($localRoot.'/docs/remove.txt');

        $service->syncBackMaterializedWorkspaces($materialized);

        Storage::disk('local')->assertExists($rootPrefix.'/docs/a.txt');
        Storage::disk('local')->assertExists($rootPrefix.'/docs/b.txt');
        Storage::disk('local')->assertMissing($rootPrefix.'/docs/remove.txt');
        $this->assertSame('alpha-updated', Storage::disk('local')->get($rootPrefix.'/docs/a.txt'));
        $this->assertSame('bravo', Storage::disk('local')->get($rootPrefix.'/docs/b.txt'));

        File::deleteDirectory($basePath);
    }
}
