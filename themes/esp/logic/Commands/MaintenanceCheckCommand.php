<?php

namespace EspTheme\Logic\Commands;

use EspTheme\Logic\MaintenanceService;
use Illuminate\Console\Command;

class MaintenanceCheckCommand extends Command
{
    protected $signature = 'esp:maintenance-check';
    protected $description = 'Run the daily maintenance status check for pages.';

    public function __construct(protected MaintenanceService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $updated = $this->service->runDailyCheck();
        $this->info(sprintf('Maintenance check complete. %d record(s) updated.', count($updated)));

        return self::SUCCESS;
    }
}
