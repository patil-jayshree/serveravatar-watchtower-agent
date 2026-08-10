<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use ServerAvatar\Watchtower\Services\ConnectionVerifier;

class WatchtowerStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watchtower:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the Watchtower Agent connection status';

    public function __construct(
        private readonly ConnectionVerifier $verifier
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Watchtower Agent');
        $this->line('----------------');
        $this->newLine();

        $result = $this->verifier->verify();

        if ($result['connected']) {
            $this->info('Status: Connected');

            if (! empty($result['project']['name'])) {
                $this->line('Project: ' . $result['project']['name']);
            }

            $this->line('Environment: ' . config('app.env', 'production'));
            $this->newLine();

            return Command::SUCCESS;
        }

        $this->error('Status: Connection Failed');
        $this->newLine();
        $this->warn('Please check:');
        $this->line('- WATCHTOWER_URL');
        $this->line('- WATCHTOWER_TOKEN');
        $this->line('- Network connectivity');
        $this->newLine();

        if (! empty($result['message'])) {
            $this->line('Error: ' . $result['message']);
            $this->newLine();
        }

        return Command::FAILURE;
    }
}
