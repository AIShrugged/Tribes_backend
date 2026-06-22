<?php

namespace Tests\Feature\Agent;

use App\Models\AgentCommandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurgeAgentCommandUndoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_purges_old_undo_payloads_but_keeps_recent_ones_and_every_audit_record(): void
    {
        $old = AgentCommandAudit::create([
            'command_type' => 'set_issue_status',
            'target_type' => 'issue',
            'target_id' => 1,
            'inverse_payload' => ['command' => 'set_issue_status', 'issue_id' => 1, 'status' => 'open'],
        ]);
        DB::table('agent_command_audit')->where('id', $old->id)->update(['created_at' => now()->subDays(8)]);

        $recent = AgentCommandAudit::create([
            'command_type' => 'set_issue_status',
            'target_type' => 'issue',
            'target_id' => 2,
            'inverse_payload' => ['command' => 'set_issue_status', 'issue_id' => 2, 'status' => 'open'],
        ]);

        $this->artisan('agent:purge-command-undo --days=7')->assertSuccessful();

        $this->assertNull($old->fresh()->inverse_payload, 'Old undo snapshot must be purged');
        $this->assertNotNull($recent->fresh()->inverse_payload, 'Recent undo snapshot must remain');
        $this->assertSame(2, AgentCommandAudit::count(), 'Audit records themselves are never deleted');
    }
}
