<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'back-office:install';

    protected $description = 'Publish the config and the migration of the back-office SDK';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'back-office-config']);
        $this->call('vendor:publish', ['--tag' => 'back-office-migrations']);

        $this->newLine();
        $this->info('Next:');
        $this->line('1. .env: BACK_OFFICE_URL, BACK_OFFICE_KEY, BACK_OFFICE_WEBHOOK_SECRET (and .env.example).');
        $this->line('2. php artisan migrate — tables back_office_outbox, back_office_webhook_events.');
        $this->line("3. routes/api.php: Route::backOfficeWebhook('webhooks/back-office'); give its URL to the service.");
        $this->line('4. Listen to Mnd\BackOffice\Laravel\Events\WebhookReceived; the scheduler sends the outbox.');

        return self::SUCCESS;
    }
}
