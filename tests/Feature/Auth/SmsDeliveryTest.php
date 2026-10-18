<?php

use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\SmsDeliveryException;
use Modules\Authentication\Sms\LogSmsSender;
use Modules\Authentication\Sms\SmsSender;
use Modules\Authentication\Sms\TwilioSmsSender;
use Modules\Transactions\Notifications\TransactionReceiptNotification;

const TWILIO_URL = 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json';

function useTwilio(array $overrides = []): void
{
    config([
        'services.sms.driver' => 'twilio',
        'services.twilio.account_sid' => 'AC123',
        'services.twilio.auth_token' => 'secret-token',
        'services.twilio.from' => '+15005550006',
        'services.twilio.messaging_service_sid' => null,
        ...$overrides,
    ]);
}

function fakeTwilio(int $status = 201, array $body = ['sid' => 'SM42', 'status' => 'queued']): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::fake(['api.twilio.com/*' => Http::response($body, $status)]);
}

test('the log driver is the default', function () {
    config(['services.sms.driver' => null]);

    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class);
});

test('the twilio driver is bound when configured', function () {
    useTwilio();

    expect(app(SmsSender::class))->toBeInstanceOf(TwilioSmsSender::class);
});

test('twilio receives the message with the number in international format', function () {
    useTwilio();
    fakeTwilio();

    $messageId = app(SmsSender::class)->send('01712345678', 'WalletMS: 123456 is your code.');

    expect($messageId)->toBe('SM42');
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === TWILIO_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret-token'))
        && $request['To'] === '+8801712345678'
        && $request['From'] === '+15005550006'
        && $request['Body'] === 'WalletMS: 123456 is your code.');
});

test('a messaging service is used instead of a sender number when configured', function () {
    useTwilio(['services.twilio.from' => null, 'services.twilio.messaging_service_sid' => 'MG777']);
    fakeTwilio();

    app(SmsSender::class)->send('01712345678', 'Hello');

    Http::assertSent(fn (HttpRequest $request): bool => $request['MessagingServiceSid'] === 'MG777'
        && ! isset($request['From']));
});

test('phone numbers are converted to E.164', function (string $input, string $expected) {
    $sender = new TwilioSmsSender('AC123', 'token', '+15005550006');

    expect($sender->toE164($input))->toBe($expected);
})->with([
    'local' => ['01712345678', '+8801712345678'],
    'with country code' => ['8801712345678', '+8801712345678'],
    'already E.164' => ['+8801712345678', '+8801712345678'],
    'international prefix' => ['008801712345678', '+8801712345678'],
    'with spaces and dashes' => ['017-1234 5678', '+8801712345678'],
]);

test('a rejected message throws and logs the provider reason without the message body', function () {
    useTwilio();
    fakeTwilio(400, ['code' => 21608, 'message' => 'The number is unverified. Trial accounts may only send to verified numbers.']);
    Log::spy();

    expect(fn () => app(SmsSender::class)->send('01712345678', 'WalletMS: 654321 is your code.'))
        ->toThrow(SmsDeliveryException::class);

    Log::shouldHaveReceived('warning')
        ->with('SMS rejected by provider', Mockery::on(fn (array $context): bool => $context['code'] === 21608
            && $context['to'] === '+88017*****678'
            && ! str_contains(json_encode($context), '654321')))
        ->once();
});

test('an unreachable provider throws a delivery exception', function () {
    useTwilio();
    Http::swap(new HttpFactory(app('events')));
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => app(SmsSender::class)->send('01712345678', 'Hello'))->toThrow(SmsDeliveryException::class);
});

test('a twilio driver without credentials refuses to send instead of failing silently', function () {
    useTwilio(['services.twilio.auth_token' => null]);
    fakeTwilio();

    expect(fn () => app(SmsSender::class)->send('01712345678', 'Hello'))->toThrow(SmsDeliveryException::class);
    Http::assertNothingSent();
});

test('a successful send logs the message id but never the body', function () {
    useTwilio();
    fakeTwilio();
    Log::spy();

    app(SmsSender::class)->send('01712345678', 'WalletMS: 777888 is your code.');

    Log::shouldHaveReceived('info')
        ->with('SMS sent', Mockery::on(fn (array $context): bool => $context['message_id'] === 'SM42'
            && ! str_contains(json_encode($context), '777888')))
        ->once();
});

test('registration codes are texted through twilio', function () {
    useTwilio();
    fakeTwilio();

    $this->postJson('/api/v1/auth/register/request-otp', ['phone_number' => '01712345678'])->assertSuccessful();

    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+8801712345678'
        && preg_match('/\b\d{6}\b/', $request['Body']) === 1);
});

test('when the code cannot be texted the customer gets a 502 instead of a false success', function () {
    useTwilio();
    fakeTwilio(503, ['message' => 'Service unavailable']);

    $this->postJson('/api/v1/auth/register/request-otp', ['phone_number' => '01712345678'])
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'We could not send the text message. Please try again shortly.');
});

test('the test command reports the log driver', function () {
    config(['services.sms.driver' => 'log']);

    $this->artisan('sms:test', ['phone' => '01712345678'])
        ->expectsOutputToContain('Driver is "log"')
        ->assertExitCode(0);
});

test('the test command sends through twilio and reports failures', function () {
    useTwilio();
    fakeTwilio();
    $this->artisan('sms:test', ['phone' => '01712345678'])
        ->expectsOutputToContain('Sent through twilio (message ID SM42).')
        ->assertExitCode(0);

    fakeTwilio(400, ['code' => 21608, 'message' => 'Unverified number']);
    $this->artisan('sms:test', ['phone' => '01712345678'])->assertExitCode(1);
});

test('transaction receipts are retried when the provider is briefly unavailable', function () {
    $notification = new TransactionReceiptNotification(new Transaction, TransactionReceiptNotification::PARTY_SENDER);

    expect($notification->tries)->toBe(3)
        ->and($notification->backoff())->toBe([30, 120]);
});
