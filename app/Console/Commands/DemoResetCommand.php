<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';
    protected $description = 'Reset database and seed pristine pitch data for hackathon demo.';

    public function handle(): int
    {
        $this->info("Resetting GabayMed hackathon database...");

        Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        $this->info('Pristine pitch state restored successfully!');
        $this->line('Simulated accounts ready:');
        $this->line('- Applicant: JOSIE SANTOS DELA CRUZ / egov_sub MVPCBEUVCGPZR (Patient: Juan D. Santos)');
        $this->line('- Hospital Staff: Dr. Ana Reyes (Manila General Hospital)');
        $this->line('- Agency Evaluator: Miguel dela Cruz (DSWD NCR)');
        $this->line('Note: the applicant matches the identity the app signs in as, so GET /api/v1/cases returns the seeded case.');

        return Command::SUCCESS;
    }
}
