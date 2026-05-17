<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('pending'); // pending | processing | done | failed
            $table->string('disk');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('properties')->nullable();  // width, height for images; pages for PDF; etc.
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'name']);
            $table->index('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX media_conversions_properties_gin ON media_conversions USING gin (properties)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_conversions');
    }
};
