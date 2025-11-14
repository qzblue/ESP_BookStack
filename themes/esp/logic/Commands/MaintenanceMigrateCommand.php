<?php

namespace EspTheme\Logic\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MaintenanceMigrateCommand extends Command
{
    protected $signature = 'esp:migrate-maintenance';
    protected $description = 'Create the page_maintenances table used by the ESP maintenance theme.';

    public function handle(): int
    {
        if (Schema::hasTable('page_maintenances')) {
            $this->info('The page_maintenances table already exists.');
            return self::SUCCESS;
        }

        Schema::create('page_maintenances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('page_id');
            $table->unsignedInteger('maintainer_user_id');
            $table->integer('period_days');
            $table->dateTime('next_due_at');
            $table->dateTime('last_reviewed_at')->nullable();
            $table->string('status', 32);
            $table->text('last_rejected_reason')->nullable();
            $table->timestamps();

            $table->foreign('page_id')
                ->references('id')
                ->on('pages')
                ->cascadeOnDelete();

            $table->foreign('maintainer_user_id')
                ->references('id')
                ->on('users');
        });

        $this->info('page_maintenances table created successfully.');

        return self::SUCCESS;
    }
}
