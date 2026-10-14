<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync code-backed Permission enum cases into the permissions database table';

    /**
     * Execute the console command.
     */
    public function handle(PermissionSeeder $seeder): int
    {
        $this->info('Syncing permission catalog to database...');
        $seeder->run();
        $this->info('Permission catalog synchronized successfully.');

        return Command::SUCCESS;
    }
}
