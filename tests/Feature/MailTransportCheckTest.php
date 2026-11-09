<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use SciFY\LaravelHealthEndpoint\Checks\MailTransportCheck;
use SciFY\LaravelHealthEndpoint\Probes\Mail\MailgunSmtpProbe;
use Spatie\Health\Checks\Result;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

describe('MailTransportCheck', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    describe('mailgun transport with the api probe', function (): void {
        beforeEach(function (): void {
            config()->set('mail.default', 'mailgun');
            config()->set('mail.mailers.mailgun.transport', 'mailgun');
            config()->set('services.mailgun.secret', 'key-test');
            config()->set('services.mailgun.endpoint', 'api.eu.mailgun.net');
            config()->set('services.mailgun.domain', 'example.org');
        });

        it('passes when the domains API answers 200', function (): void {
            // Arrange
            Http::fake(['https://api.eu.mailgun.net/v3/domains/example.org' => Http::response(['domain' => []], 200)]);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('ok')
                ->and($result->getShortSummary())->toBe('Connected')
                ->and($result->meta)->toBe(['driver' => 'mailgun']);
            Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Basic ' . base64_encode('api:key-test')));
        });

        it('fails with both causes named when Mailgun answers 401', function (): void {
            // Arrange
            Http::fake(['*' => Http::response(['message' => 'Invalid private key'], 401)]);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Rejected (401): invalid key or IP not allowlisted');
        });

        it('fails as unreachable when Mailgun answers 500', function (): void {
            // Arrange
            Http::fake(['*' => Http::response('', 500)]);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Unreachable');
        });

        it('fails as unreachable when the connection fails', function (): void {
            // Arrange
            Http::fake(['*' => Http::failedConnection()]);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Unreachable');
        });

        it('throws when the Mailgun secret is missing, so the runner reports it as crashed', function (): void {
            // Arrange
            config()->set('services.mailgun.secret');

            // Act + Assert
            expect(fn (): Result => MailTransportCheck::new()->run())->toThrow(InvalidArgumentException::class);
        });
    });

    describe('mailgun transport with the smtp probe', function (): void {
        beforeEach(function (): void {
            config()->set('mail.default', 'mailgun');
            config()->set('mail.mailers.mailgun.transport', 'mailgun');
            config()->set('health-endpoint.options.mail.probes.mailgun', MailgunSmtpProbe::class);
            // Port 1 on localhost refuses at once, so the tests need no network.
            config()->set('health-endpoint.options.mail.mailgun_smtp_host', '127.0.0.1');
            config()->set('health-endpoint.options.mail.mailgun_smtp_port', 1);
        });

        it('fails without connecting when the SMTP credentials are not configured', function (): void {
            // Arrange: Symfony skips AUTH for an empty username, so a reachable server would report a false ok.
            config()->set('health-endpoint.options.mail.mailgun_smtp_username', '');
            config()->set('health-endpoint.options.mail.mailgun_smtp_password', '');

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('SMTP credentials not configured');
        });

        it('fails as unreachable when the Mailgun SMTP host refuses the connection', function (): void {
            // Arrange
            config()->set('health-endpoint.options.mail.mailgun_smtp_username', 'postmaster@example.org');
            config()->set('health-endpoint.options.mail.mailgun_smtp_password', 'secret');

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Unreachable')
                ->and($result->meta)->toBe(['driver' => 'mailgun']);
            Http::assertNothingSent();
        });
    });

    describe('smtp transport', function (): void {
        beforeEach(function (): void {
            config()->set('mail.default', 'smtp');
            config()->set('mail.mailers.smtp.transport', 'smtp');
        });

        it('passes when the handshake succeeds, without sending a message', function (): void {
            // Arrange
            $transport = Mockery::mock(EsmtpTransport::class);
            $transport->shouldReceive('getStream')->andReturn(new SocketStream);
            $transport->shouldReceive('start')->once();
            $transport->shouldReceive('stop')->once();
            $transport->shouldNotReceive('send');
            Mail::extend('smtp', fn () => $transport);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('ok')
                ->and($result->getShortSummary())->toBe('Connected')
                ->and($result->meta)->toBe(['driver' => 'smtp']);
        });

        it('fails with authentication failed when AUTH is rejected', function (): void {
            // Arrange
            $transport = Mockery::mock(EsmtpTransport::class);
            $transport->shouldReceive('getStream')->andReturn(new SocketStream);
            $transport->shouldReceive('start')->andThrow(new TransportException(
                'Failed to authenticate on SMTP server with username "user" using the following authenticators: "LOGIN", "PLAIN".',
                535,
            ));
            Mail::extend('smtp', fn () => $transport);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Authentication failed');
        });

        it('fails as unreachable when the connection cannot be established', function (): void {
            // Arrange
            $transport = Mockery::mock(EsmtpTransport::class);
            $transport->shouldReceive('getStream')->andReturn(new SocketStream);
            $transport->shouldReceive('start')->andThrow(new TransportException('Connection could not be established with host "smtp.example.org:587".'));
            Mail::extend('smtp', fn () => $transport);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('failed')
                ->and($result->getShortSummary())->toBe('Unreachable');
        });

        it('skips an smtp mailer whose transport is not ESMTP', function (): void {
            // Arrange: a custom transport registered under the smtp name, which the probe cannot handshake with.
            Mail::extend('smtp', fn (): NullTransport => new NullTransport);

            // Act
            $result = MailTransportCheck::new()->run();

            // Assert
            expect($result->status->value)->toBe('skipped')
                ->and($result->getShortSummary())->toBe('Driver not supported')
                ->and($result->meta)->toBe(['driver' => 'smtp']);
        });
    });

    it('skips transports without a probe', function (): void {
        // Arrange
        config()->set('mail.default', 'ses');
        config()->set('mail.mailers.ses.transport', 'ses');

        // Act
        $result = MailTransportCheck::new()->run();

        // Assert
        expect($result->status->value)->toBe('skipped')
            ->and($result->getShortSummary())->toBe('Driver not supported')
            ->and($result->meta)->toBe(['driver' => 'ses']);
    });
});
