<?php

namespace EspTheme\Logic\Commands;

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
        if (Schema::hasTable('page_maintenances')) {
            $this->info('The page_maintenances table already exists.');
            return self::SUCCESS;
        }

        $usesBigPageIds = $this->columnUsesBigInteger('pages', 'id');
        $usesBigUserIds = $this->columnUsesBigInteger('users', 'id');

        Schema::create('page_maintenances', function (Blueprint $table) use ($usesBigPageIds, $usesBigUserIds) {
            $table->bigIncrements('id');
            if ($usesBigPageIds) {
                $table->unsignedBigInteger('page_id');
            } else {
                $table->unsignedInteger('page_id');
            }

            if ($usesBigUserIds) {
                $table->unsignedBigInteger('maintainer_user_id');
            } else {
                $table->unsignedInteger('maintainer_user_id');
            }
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

    private function columnUsesBigInteger(string $table, string $column): bool
    {
        $connection = Schema::getConnection();
        $prefixedTable = $connection->getTablePrefix() . $table;

        $likeValue = str_replace("'", "''", $column);
        $columnData = DB::selectOne("SHOW COLUMNS FROM `{$prefixedTable}` LIKE '{$likeValue}'");

        if (!$columnData || !property_exists($columnData, 'Type')) {
            return false;
        }

        return str_contains(strtolower($columnData->Type), 'bigint');
    }
}
