<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Commands;

use Illuminate\Console\Command;
use ServerAvatar\Watchtower\Installer\WatchtowerInstaller;

class WatchtowerInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watchtower:install
                            {--url= : Watchtower base URL (e.g. https://watchtower.example.com)}
                            {--token= : Agent token from Watchtower project settings}
                            {--skip-validation : Skip token validation (not recommended)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure the Watchtower Agent for Laravel';

    public function __construct(
        private readonly WatchtowerInstaller $installer
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();

        // Step 1: Get Watchtower URL
        $url = $this->getWatchtowerUrl();

        // Step 2: Get Agent Token
        $token = $this->getToken();

        // Step 3: Validate token (unless skipped)
        if (! $this->option('skip-validation')) {
            $this->validateToken($url, $token);
        }

        // Step 4: Run installation
        $this->info('Installing Watchtower Agent...');
        $this->line('');

        $result = $this->installer->install($url, $token);

        if (! $result['success']) {
            $this->error('Installation failed: ' . $result['message']);
            $this->line('');
            $this->warn('No changes were made to your .env file.');

            return self::FAILURE;
        }

        // Step 5: Display results
        $this->displaySuccess($result);

        return self::SUCCESS;
    }

    /**
     * Display the installation header.
     */
    protected function displayHeader(): void
    {
        $this->line('');
        $this->info('  ██╗   ██╗███╗   ███╗██╗███╗   ██╗███████╗');
        $this->info('  ██║   ██║████╗ ████║██║████╗  ██║██╔════╝');
        $this->info('  ██║   ██║██╔████╔██║██║██╔██╗ ██║█████╗  ');
        $this->info('  ╚██╗ ██╔╝██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ');
        $this->info('   ╚████╔╝ ██║ ╚═╝ ██║██║██║ ╚████║███████╗');
        $this->info('    ╚═══╝  ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝');
        $this->line('');
        $this->info('  Laravel Agent Installer');
        $this->line('  ───────────────────────────────────────');
        $this->line('');
    }

    /**
     * Get Watchtower URL from options or prompt.
     */
    protected function getWatchtowerUrl(): string
    {
        // Check if already configured in .env
        $existingUrl = config('watchtower.url') ?: env('WATCHTOWER_URL');

        $url = $this->option('url');

        if (empty($url)) {
            if (! empty($existingUrl)) {
                $url = $this->anticipate(
                    'Watchtower URL',
                    [$existingUrl],
                    $existingUrl
                );
            } else {
                $url = $this->ask('Watchtower URL', 'https://watchtower.example.com');
            }
        }

        return $this->normalizeUrl($url);
    }

    /**
     * Get token from options or prompt.
     */
    protected function getToken(): string
    {
        $token = $this->option('token');

        if (empty($token)) {
            // Check if already configured in .env
            $existingToken = config('watchtower.token') ?: env('WATCHTOWER_TOKEN');

            if (! empty($existingToken)) {
                $this->info('Token already configured in .env');
                $token = $this->secret('Enter new token to replace (leave blank to keep existing)');

                if (empty($token)) {
                    $this->warn('Keeping existing token.');
                    return $existingToken;
                }
            } else {
                $token = $this->secret('Enter your Watchtower Agent Token');
            }
        }

        if (empty(trim($token))) {
            $this->error('Token is required.');

            return $this->getToken();
        }

        return trim($token);
    }

    /**
     * Validate token against Watchtower.
     */
    protected function validateToken(string $url, string $token): void
    {
        $this->info('Validating token...');

        $result = $this->installer->validateToken($url, $token);

        if (! $result['success']) {
            $this->error('Token validation failed: ' . $result['message']);

            // Only retry in interactive mode
            if ($this->isInteractive() && $this->confirm('Would you like to try again?', true)) {
                $token = $this->getToken();
                $this->validateToken($url, $token);
            } else {
                $this->warn('Proceeding without validation (token may be invalid).');
            }

            return;
        }

        $projectName = $result['project']['name'] ?? 'Unknown Project';
        $this->info("✓ Token validated successfully!");
        $this->line("  Connected to: {$projectName}");
        $this->line('');
    }

    /**
     * Check if the command is running in interactive mode.
     */
    protected function isInteractive(): bool
    {
        return $this->input && $this->input->isInteractive();
    }

    /**
     * Display success message and configuration summary.
     *
     * @param  array{success: bool, message: string, configured: array<string, string|bool>, skipped: array<string, string>}  $result
     */
    protected function displaySuccess(array $result): void
    {
        $this->info('✓ Installation completed successfully!');
        $this->line('');
        $this->info('Configuration applied:');
        $this->line('');

        // Core settings (show first)
        $this->line('  Core Settings:');
        $this->line('    • Agent enabled: true');
        $this->line('    • Telemetry: enabled');
        $this->line('    • Exception capture: enabled');
        $this->line('');

        // Monitoring features
        $this->line('  Monitoring Features:');
        $this->line('    • HTTP Requests: enabled');
        $this->line('    • SQL Queries: enabled');
        $this->line('    • Exceptions: enabled');
        $this->line('    • Commands: enabled');
        $this->line('    • Jobs/Queue: enabled');
        $this->line('    • Logs: enabled');
        $this->line('    • Scheduler: enabled');
        $this->line('');

        // Note about configured vs skipped
        $configuredCount = count($result['configured']);
        $skippedCount = count($result['skipped']);

        if ($skippedCount > 0) {
            $this->line("  ({$skippedCount} existing setting(s) preserved)");
        }

        $this->line('');
        $this->info('  Next Steps:');
        $this->line('');
        $this->line('    1. Verify connection:');
        $this->line('       php artisan watchtower:status');
        $this->line('');

        $this->line('    2. Trigger some activity in your app to generate telemetry:');
        $this->line('       - Visit some routes');
        $this->line('       - Run artisan commands');
        $this->line('       - Dispatch queue jobs');
        $this->line('');

        $this->line('    3. View telemetry in Watchtower at:');
        $watchtowerUrl = config('watchtower.url') ?: env('WATCHTOWER_URL') ?: '<your-watchtower-url>';
        $this->line("       {$watchtowerUrl}");
        $this->line('');
    }

    /**
     * Normalize a URL.
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (empty($url)) {
            return '';
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }
}
