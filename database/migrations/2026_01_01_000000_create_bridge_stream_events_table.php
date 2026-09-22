<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('bridge.stream.drivers.database.table', 'bridge_stream_events');

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('channel', 190)->index();
            $table->string('event', 120);
            $table->text('data');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('bridge.stream.drivers.database.table', 'bridge_stream_events'));
    }
};
