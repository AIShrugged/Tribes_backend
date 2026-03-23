<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_many_calendar_events_relation()
    {
        $source = Source::factory()->create();
        $this->assertTrue($source->calendarEvents()->getRelated() instanceof \Illuminate\Database\Eloquent\Relations\HasMany);
    }

    public function test_it_belongs_to_user_relation()
    {
        $source = Source::factory()->create();
        $this->assertTrue($source->user()->getRelated() instanceof User);
    }

    public function test_it_can_make_auth_driver()
    {
        $source = Source::factory()->create();
        $driver = $source->makeAuthDriver();
        $this->assertIsObject($driver);
    }

    public function test_it_can_apply_auth_and_return_array()
    {
        $source = Source::factory()->create();
        $result = $source->applyAuth([]);
        $this->assertIsArray($result);
    }

    public function test_scope_owned_returns_builder_with_user_id_condition()
    {
        $source = Source::factory()->create();
        $query = Source::owned($source->user_id)->toBase();
        $sql = $query->toSql();
        $this->assertStringContainsString('where', $sql);
        $this->assertStringContainsString('user_id', $sql);
    }

    public function test_it_can_disconnect()
    {
        $source = Source::factory()->create(['is_connected' => true]);
        $source->disconnect();
        $this->assertFalse($source->fresh()->is_connected);
    }
}