<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Commands;

use Illuminate\Console\Command;
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
    protected $description = 'Check the Watchtower Agent connection and monitoring status';

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
        $this->displayHeader();

        $result = $this->verifier->verify();

        if (! $result['connected']) {
            $this->displayDisconnected($result['message'] ?? 'Connection failed');

            return self::FAILURE;
        }

        $this->displayConnected($result['project'] ?? []);

        return self::SUCCESS;
    }

    /**
     * Display the status header.
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
        $this->info('  Laravel Agent Status');
        $this->line('  ───────────────────────────────────────');
        $this->line('');
    }

    /**
     * Display connected status with project info and monitoring features.
     *
     * @param  array{name?: string, id?: int}  $project
     */
    protected function displayConnected(array $project): void
    {
        // Connection Status
        $this->info('  ✓ Status: Connected');
        $this->line('');

        // Project Info
        $this->line('  Project Details');
        $this->line('  ───────────────');

        $projectName = $project['name'] ?? 'Unknown Project';
        $projectId = $project['id'] ?? 'N/A';

        $this->line("  Name:       {$projectName}");
        $this->line("  Project ID: {$projectId}");
        $this->line("  Environment: " . config('app.env', 'production'));
        $this->line("  Agent Ver:  " . config('watchtower.agent_version', '1.0.0'));
        $this->line('');

        // Monitoring Features
        $this->line('  Monitoring Features');
        $this->line('  ─────────────────');

        $features = $this->getMonitoringFeatures();

        foreach ($features as $feature => $value) {
            $isEnabled = is_bool($value) ? $value : ($value !== false && $value !== 0);
            $indicator = $isEnabled ? '✓' : '✗';
            $displayValue = $this->formatFeatureValue($value);

            // Special case: show threshold values
            $suffix = '';
            if ($feature === 'Slow Query Threshold') {
                $suffix = 'ms';
            }

            $this->line("  {$indicator} {$feature}: {$displayValue}{$suffix}");
        }

        $this->line('');

        // Endpoint Info (without token)
        $this->line('  Endpoint');
        $this->line('  ─────────');
        $watchtowerUrl = config('watchtower.url') ?: env('WATCHTOWER_URL', 'Not configured');
        $this->line("  URL: {$watchtowerUrl}");
        $this->line('');
    }

    /**
     * Display disconnected status with troubleshooting steps.
     */
    protected function displayDisconnected(string $message): void
    {
        $this->error('  ✗ Status: Disconnected');
        $this->line('');
        $this->line('  Error: ' . $message);
        $this->line('');

        $this->line('  Troubleshooting');
        $this->line('  ─────────────');
        $this->line('');

        $this->line('  1. Verify your WATCHTOWER_URL and WATCHTOWER_TOKEN in .env');
        $this->line('');

        $this->line('  2. Check network connectivity:');
        $this->line('     curl ' . (config('watchtower.url') ?: 'https://your-watchtower-url'));
        $this->line('');

        $this->line('  3. Validate your token at:');
        $url = config('watchtower.url') ?: '<your-watchtower-url>';
        $this->line("     {$url}/settings/agent-tokens");
        $this->line('');

        $this->line('  4. Re-run installation:');
        $this->line('     php artisan watchtower:install');
        $this->line('');

        $this->line('  5. For debugging, enable debug mode in .env:');
        $this->line('     WATCHTOWER_DEBUG=true');
        $this->line('');

        $this->line('  Then check Laravel logs:');
        $this->line('     tail -f storage/logs/laravel.log');
        $this->line('');
    }

    /**
     * Get the status of all monitoring features.
     *
     * @return array<string, bool>
     */
    protected function getMonitoringFeatures(): array
    {
        return [
            'HTTP Requests' => (bool) config('watchtower.request_telemetry.enabled', false),
            'Exceptions' => (bool) config('watchtower.exceptions.enabled', false),
            'Capture HTTP Errors' => (bool) config('watchtower.exceptions.capture_http_errors', false),
            'SQL Queries' => (bool) config('watchtower.query_monitoring.enabled', false),
            'Slow Query Threshold' => (int) config('watchtower.query_monitoring.slow_query_threshold', 500),
            'Commands' => (bool) config('watchtower.command_monitoring.enabled', false),
            'Jobs/Queue' => (bool) config('watchtower.queue_monitoring.enabled', false),
            'Logs' => (bool) config('watchtower.log_monitoring.enabled', false),
            'Scheduler' => (bool) config('watchtower.scheduler_monitoring.enabled', false),
        ];
    }

    /**
     * Format a monitoring feature value for display.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function formatFeatureValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return (string) $value;
    }
}
