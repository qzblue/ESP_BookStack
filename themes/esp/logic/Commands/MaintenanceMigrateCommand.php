<?php

namespace EspTheme\Logic\Commands;

use BookStack\Entities\Models\EntityPageData;
use BookStack\Entities\Models\Page;
use BookStack\Users\Models\User as BookStackUser;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintenanceMigrateCommand extends Command
{
    protected $signature = 'esp:migrate-maintenance';
    protected $description = 'Create the page_maintenances table used by the ESP maintenance theme.';

    public function handle(): int
    {
        $tableName = 'page_maintenances';

        $pageDataTable = (new EntityPageData())->getTable();
        $pageMorphClass = (new Page())->getMorphClass();
        $pageRevisionTable = (new Page())->revisions()->getModel()->getTable();
        $userTable = (new BookStackUser())->getTable();

        if (!Schema::hasTable($pageDataTable) || !Schema::hasTable($userTable)) {
            $this->error('Core tables not found. Run the standard BookStack migrations before provisioning maintenance tables.');
            return self::FAILURE;
        }

        if (Schema::hasTable($tableName)) {
            $updated = false;

            if (!Schema::hasColumn($tableName, 'period_hours')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedTinyInteger('period_hours')->default(0)->after('period_days');
                });
                $updated = true;
            }

            if (!Schema::hasColumn($tableName, 'period_minutes')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedTinyInteger('period_minutes')->default(0)->after('period_hours');
                });
                $updated = true;
            }

            if (!Schema::hasColumn($tableName, 'page_type')) {
                Schema::table($tableName, function (Blueprint $table) use ($pageMorphClass) {
                    $table->string('page_type', 191)->default($pageMorphClass)->after('page_id');
                });

                DB::table($tableName)
                    ->whereNull('page_type')
                    ->orWhere('page_type', '')
                    ->update(['page_type' => $pageMorphClass]);

                $updated = true;
            }

            if (!Schema::hasColumn($tableName, 'last_approved_revision_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($pageRevisionTable) {
                    $table->unsignedInteger('last_approved_revision_id')->nullable()->after('status');

                    $table->foreign('last_approved_revision_id')
                        ->references('id')
                        ->on($pageRevisionTable)
                        ->nullOnDelete();
                });
                $updated = true;
            }

            if ($updated) {
                $this->info('page_maintenances table updated successfully.');
            } else {
                $this->info('The page_maintenances table already exists and is up to date.');
            }

            return self::SUCCESS;
        }

        Schema::create($tableName, function (Blueprint $table) use ($pageDataTable, $userTable, $pageMorphClass, $pageRevisionTable) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('page_id');
            $table->string('page_type', 191)->default($pageMorphClass);
            $table->unsignedInteger('maintainer_user_id');
            $table->integer('period_days');
            $table->unsignedTinyInteger('period_hours')->default(0);
            $table->unsignedTinyInteger('period_minutes')->default(0);
            $table->dateTime('next_due_at');
            $table->dateTime('last_reviewed_at')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('last_approved_revision_id')->nullable();
            $table->text('last_rejected_reason')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'page_type']);

            $table->foreign('page_id')
                ->references('page_id')
                ->on($pageDataTable)
                ->cascadeOnDelete();

            $table->foreign('maintainer_user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();

            $table->foreign('last_approved_revision_id')
                ->references('id')
                ->on($pageRevisionTable)
                ->nullOnDelete();
        });

        $this->info('page_maintenances table created successfully.');

        return self::SUCCESS;
    }
}
