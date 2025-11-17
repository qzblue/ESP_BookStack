<?php

use BookStack\Maintenance\MaintenanceTask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('period')->default('monthly');
            $table->dateTime('next_due_at');
            $table->dateTime('last_maintained_at')->nullable();
            $table->string('status')->default(MaintenanceTask::STATUS_PENDING);
            $table->unsignedBigInteger('draft_revision_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};
