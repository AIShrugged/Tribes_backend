<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Tests\TestCase;

class WorkspaceServiceTest extends TestCase
{
    public function test_resolve_storage_path_rejects_path_traversal(): void
    {
        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        $service = new WorkspaceService;

        $this->expectException(\InvalidArgumentException::class);
        $service->resolveStoragePath($workspace, '../secrets.txt');
    }

    public function test_resolve_storage_path_joins_root_and_relative_path(): void
    {
        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        $service = new WorkspaceService;

        $this->assertSame(
            'workspaces/orgs/acme/shared/docs/spec.md',
            $service->resolveStoragePath($workspace, '/docs/spec.md')
        );
    }

    public function test_search_copy_and_move_workspace_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        $service = new WorkspaceService;

        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/docs/spec.txt', 'spec');

        $results = $service->searchFiles($workspace, 'spec');
        $this->assertCount(1, $results);
        $this->assertSame('docs/spec.txt', $results[0]['path']);

        $this->assertTrue($service->copy($workspace, 'docs/spec.txt', 'docs/spec-copy.txt'));
        $this->assertTrue($service->move($workspace, 'docs/spec-copy.txt', 'archive/spec-copy.txt'));

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('workspaces/orgs/acme/shared/docs/spec.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('workspaces/orgs/acme/shared/archive/spec-copy.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('workspaces/orgs/acme/shared/docs/spec-copy.txt');
    }

    public function test_read_file_with_limit_reports_truncation_metadata(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/docs/large.txt', str_repeat('a', 32));

        $service = new WorkspaceService;
        $result = $service->readFileWithLimit($workspace, 'docs/large.txt', 10);

        $this->assertSame('aaaaaaaaaa', $result['contents']);
        $this->assertSame(32, $result['size_bytes']);
        $this->assertSame(10, $result['returned_bytes']);
        $this->assertTrue($result['truncated']);
    }

    public function test_make_directory_and_recursive_delete_work_on_directories(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        $service = new WorkspaceService;

        $this->assertTrue($service->makeDirectory($workspace, 'nested/docs'));
        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/nested/docs/a.txt', 'alpha');
        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/nested/docs/b.txt', 'beta');

        $this->assertTrue($service->delete($workspace, 'nested'));
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('workspaces/orgs/acme/shared/nested/docs/a.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('workspaces/orgs/acme/shared/nested/docs/b.txt');
    }

    public function test_copy_and_move_support_directories(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/docs/tree/a.txt', 'a');
        \Illuminate\Support\Facades\Storage::disk('local')->put('workspaces/orgs/acme/shared/docs/tree/b.txt', 'b');

        $service = new WorkspaceService;

        $this->assertTrue($service->copy($workspace, 'docs/tree', 'copies/tree'));
        $this->assertTrue($service->move($workspace, 'copies/tree', 'archive/tree'));

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('workspaces/orgs/acme/shared/docs/tree/a.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('workspaces/orgs/acme/shared/archive/tree/a.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('workspaces/orgs/acme/shared/archive/tree/b.txt');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('workspaces/orgs/acme/shared/copies/tree/a.txt');
    }

    public function test_list_contents_ignores_directory_marker_objects(): void
    {
        $workspace = new Workspace([
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('directories')
            ->once()
            ->with('workspaces/orgs/acme/shared')
            ->andReturn(['workspaces/orgs/acme/shared/notes']);
        $disk->shouldReceive('files')
            ->once()
            ->with('workspaces/orgs/acme/shared')
            ->andReturn(['workspaces/orgs/acme/shared/notes']);
        $disk->shouldReceive('directories')
            ->once()
            ->with('workspaces/orgs/acme/shared/notes')
            ->andReturn([]);
        $disk->shouldReceive('files')
            ->once()
            ->with('workspaces/orgs/acme/shared/notes')
            ->andReturn(['workspaces/orgs/acme/shared/notes/secret.txt']);
        $disk->shouldReceive('size')
            ->once()
            ->with('workspaces/orgs/acme/shared/notes/secret.txt')
            ->andReturn(6);
        $disk->shouldReceive('lastModified')
            ->once()
            ->with('workspaces/orgs/acme/shared/notes/secret.txt')
            ->andReturn(1_700_000_000);

        \Illuminate\Support\Facades\Storage::shouldReceive('disk')
            ->with('local')
            ->andReturn($disk);

        $service = new WorkspaceService;

        $rootListing = $service->listContents($workspace);
        $notesListing = $service->listContents($workspace, 'notes');

        $this->assertSame(['notes'], $rootListing['directories']);
        $this->assertSame([], $rootListing['files']);
        $this->assertSame('notes', $notesListing['path']);
        $this->assertCount(1, $notesListing['files']);
        $this->assertSame('notes/secret.txt', $notesListing['files'][0]['path']);
    }
}
