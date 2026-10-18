<?php

namespace Modules\Authentication\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\SmsDeliveryException;

/**
 * Sends SMS through Twilio's Messages API.
 *
 * A free Twilio trial account works for this demo: it can only send to phone numbers verified in
 * the Twilio console, and Twilio prefixes each message with "Sent from your Twilio trial account".
 * Message bodies are never logged, because they carry OTP codes.
 */
class TwilioSmsSender implements SmsSender
{
    public function __construct(
        private string $accountSid,
        private string $authToken,
        private ?string $from = null,
        private ?string $messagingServiceSid = null,
        private string $defaultCountryCode = '880',
        private int $timeoutSeconds = 10,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function send(string $phoneNumber, string $message): ?string
    {
        if ($this->accountSid === '' || $this->authToken === '' || (! $this->from && ! $this->messagingServiceSid)) {
            Log::error('SMS driver is "twilio" but TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM (or TWILIO_MESSAGING_SERVICE_SID) are not all set');

            throw new SmsDeliveryException;
        }

        $to = $this->toE164($phoneNumber);

        $payload = ['To' => $to, 'Body' => $message];
        if ($this->messagingServiceSid) {
            $payload['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $payload['From'] = (string) $this->from;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout($this->timeoutSeconds)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", $payload);
        } catch (ConnectionException $e) {
            Log::warning('SMS provider unreachable', ['provider' => 'twilio', 'to' => $this->mask($to)]);

            throw new SmsDeliveryException(previous: $e);
        }

        if ($response->failed()) {
            Log::warning('SMS rejected by provider', [
                'provider' => 'twilio',
                'to' => $this->mask($to),
                'status' => $response->status(),
                'code' => $response->json('code'),
                'error' => $response->json('message'),
            ]);

            throw new SmsDeliveryException;
        }

        $messageId = $response->json('sid');

        Log::info('SMS sent', [
            'provider' => 'twilio',
            'to' => $this->mask($to),
            'message_id' => $messageId,
            'status' => $response->json('status'),
        ]);

        return $messageId;
    }

    /**
     * Turn a local number (01712345678) into E.164 (+8801712345678). Numbers already in international
     * form are kept.
     */
    public function toE164(string $phoneNumber): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phoneNumber) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, $this->defaultCountryCode)) {
            return '+'.$digits;
        }

        return '+'.$this->defaultCountryCode.ltrim($digits, '0');
    }

    private function mask(string $phoneNumber): string
    {
        return strlen($phoneNumber) > 7
            ? substr($phoneNumber, 0, 6).str_repeat('*', strlen($phoneNumber) - 9).substr($phoneNumber, -3)
            : '***';
    }
}
