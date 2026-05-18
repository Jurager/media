<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->uuid('uuid')->unique();
            $table->string('collection_name')->default('default');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->string('hash', 32)->nullable()->index();
            $table->jsonb('properties')->nullable();
            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'collection_name'], 'media_mediable_collection_index');
        });

        // GIN index for efficient JSON property queries (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX media_properties_gin ON media USING gin (properties)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
