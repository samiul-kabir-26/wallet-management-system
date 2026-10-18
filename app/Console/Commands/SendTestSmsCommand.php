<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Authentication\Exceptions\SmsDeliveryException;
use Modules\Authentication\Sms\SmsSender;

class SendTestSmsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:test
                            {phone : Phone number, e.g. 01712345678}
                            {--message=WalletMS test message. If you got this, SMS delivery works. : Text to send}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send one text message through the configured SMS driver to check the setup';

    /**
     * Execute the console command.
     */
    public function handle(SmsSender $sender): int
    {
        $driver = (string) config('services.sms.driver', 'log');
        $phone = (string) $this->argument('phone');

        try {
            $messageId = $sender->send($phone, (string) $this->option('message'));
        } catch (SmsDeliveryException $e) {
            $this->error("The {$driver} driver could not send the message.");
            $this->line('Reason: '.($e->providerReason ?? $e->getMessage()));

            return self::FAILURE;
        }

        $this->info($driver === 'log'
            ? 'Driver is "log": the message was written to the log, not sent. Set SMS_DRIVER=twilio to send real texts.'
            : "Sent through {$driver}".($messageId ? " (message ID {$messageId})." : '.'));

        return self::SUCCESS;
    }
}
