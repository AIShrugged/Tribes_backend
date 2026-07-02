<?php

namespace Tests\Feature;

use App\Services\Organization\ProjectCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private ProjectCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ProjectCodeGenerator;
    }

    private function seedOrgCode(string $code): void
    {
        DB::table('organizations')->insert([
            'name' => 'Seed '.$code,
            'slug' => 'seed-'.strtolower($code),
            'code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_derives_the_base_code_from_the_first_letters(): void
    {
        $this->assertSame('AUC', $this->generator->generate('Auchan'));
        $this->assertSame('DEV', $this->generator->generate('Dev_coding'));
        $this->assertSame('MAR', $this->generator->generate('Marketing Department'));
    }

    #[Test]
    public function it_varies_letters_on_collision_preferring_next_word_initials(): void
    {
        $this->seedOrgCode('DEV');

        // "Dev_frontend-code" would also derive DEV; the third letter varies to F (frontend).
        $this->assertSame('DEF', $this->generator->generate('Dev_frontend-code'));
    }

    #[Test]
    public function it_falls_back_to_a_numeric_suffix_when_letters_are_exhausted(): void
    {
        $this->seedOrgCode('AAA');

        $this->assertSame('AAA2', $this->generator->generate('Aaa'));
    }

    #[Test]
    public function it_transliterates_cyrillic_names(): void
    {
        // Str::ascii('Ашан') === 'Asan' → first three letters uppercased.
        $this->assertSame('ASA', $this->generator->generate('Ашан'));
    }

    #[Test]
    public function it_pads_short_names_to_the_minimum_length(): void
    {
        $this->assertSame('GOX', $this->generator->generate('Go'));
    }

    #[Test]
    public function it_honours_in_batch_reserved_codes(): void
    {
        // Nothing in the DB, but DEV is reserved in-memory (e.g. mid-backfill).
        $this->assertSame('DEC', $this->generator->generate('Dev_coding', ['DEV']));
    }

    #[Test]
    public function generated_codes_respect_the_length_and_format_bounds(): void
    {
        foreach (['Auchan', 'Go', 'Ашан', 'A', '123', 'X'] as $name) {
            $code = $this->generator->generate($name);
            $this->assertGreaterThanOrEqual(ProjectCodeGenerator::MIN_LENGTH, strlen($code));
            $this->assertLessThanOrEqual(ProjectCodeGenerator::MAX_LENGTH, strlen($code));
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9]*$/', $code, "Invalid code for '{$name}'");
            $this->seedOrgCode($code); // force distinct codes across iterations
        }
    }
}
