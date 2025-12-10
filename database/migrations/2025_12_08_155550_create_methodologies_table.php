<?php

use App\Models\Methodology;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('methodologies', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('name');
            $table->text('text');
            $table->text('scheme')->nullable();
            $table->string('scheme_version')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Methodology::create([
            'user_id' => null,
            'name' => 'Стандартная методология',
            'text' => file_get_contents(resource_path('/prompts/default_methodology.md')),
            'scheme' => file_get_contents(resource_path('prompts/default_methodology_scheme')),
            'scheme_version' => '1',
            'is_default' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('methodologies');
    }
};
