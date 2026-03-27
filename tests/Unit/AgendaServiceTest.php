<?php

namespace Tests\Unit;

use App\Services\Agenda\AgendaService;
use App\Services\Agenda\PreviousMeetingResolver;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AgendaServiceTest extends TestCase
{
    #[Test]
    public function normalize_date_time_parses_string_values(): void
    {
        $service = new AgendaService($this->createMock(PreviousMeetingResolver::class));

        $value = $this->invokeNormalizeDateTime($service, '2026-03-27 10:30:00');

        $this->assertInstanceOf(Carbon::class, $value);
        $this->assertSame('2026-03-27 10:30:00', $value->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function normalize_date_time_keeps_carbon_instances(): void
    {
        $service = new AgendaService($this->createMock(PreviousMeetingResolver::class));
        $original = Carbon::parse('2026-03-27 10:30:00');

        $value = $this->invokeNormalizeDateTime($service, $original);

        $this->assertSame($original, $value);
    }

    private function invokeNormalizeDateTime(AgendaService $service, mixed $value): Carbon
    {
        $method = new \ReflectionMethod($service, 'normalizeDateTime');
        $method->setAccessible(true);

        /** @var Carbon $normalized */
        $normalized = $method->invoke($service, $value);

        return $normalized;
    }
}
