<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MailConfigurationException;
use App\Exceptions\MailDeliveryException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use InvalidArgumentException;

final class MailService
{
    public function assertConfigured(): void
    {
        $host = app_config('MAIL_HOST');
        $port = filter_var(app_config('MAIL_PORT'), FILTER_VALIDATE_INT);
        $username = app_config('MAIL_USERNAME');
        $password = app_config('MAIL_PASSWORD');
        $encryption = app_config('MAIL_ENCRYPTION', 'tls');
        $fromAddress = app_config('MAIL_FROM_ADDRESS');
        $fromName = app_config('MAIL_FROM_NAME', 'Mi Finanzas');

        if (
            !$host
            || !$port
            || $port < 1
            || $port > 65535
            || !$username
            || $password === null
            || $password === ''
            || !$fromAddress
            || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
            || !in_array($encryption, ['tls', 'ssl'], true)
        ) {
            throw new MailConfigurationException('Completa la configuración SMTP requerida en .env.');
        }

        if (
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && !filter_var($host, FILTER_VALIDATE_IP)
        ) {
            throw new MailConfigurationException('El servidor SMTP configurado no es válido.');
        }
    }

    public function send(string $recipient, string $subject, string $body): void
    {
        $this->assertConfigured();
        $host = (string) app_config('MAIL_HOST');
        $port = (int) app_config('MAIL_PORT');
        $username = (string) app_config('MAIL_USERNAME');
        $password = (string) app_config('MAIL_PASSWORD');
        $encryption = app_config('MAIL_ENCRYPTION', 'tls');
        $fromAddress = (string) app_config('MAIL_FROM_ADDRESS');
        $fromName = app_config('MAIL_FROM_NAME', 'Mi Finanzas');
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $dsnHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $host . ']'
            : $host;
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $dsnHost,
            $port
        );

        try {
            $mailer = new Mailer(Transport::fromDsn($dsn));
            $email = (new Email())
                ->from(new Address($fromAddress, $fromName ?: 'Mi Finanzas'))
                ->to($recipient)
                ->subject($subject)
                ->text($body);
            $mailer->send($email);
        } catch (InvalidArgumentException $exception) {
            throw new MailConfigurationException('La configuración SMTP del servidor no es válida.', 0, $exception);
        } catch (TransportExceptionInterface $exception) {
            throw new MailDeliveryException($exception);
        }
    }
}
