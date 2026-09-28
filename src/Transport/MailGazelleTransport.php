<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Transport;

use MailGazelle\Client;
use MailGazelle\Emails\Email as MailGazelleEmail;
use MailGazelle\Exceptions\MailGazelleException;
use MailGazelle\ValueObjects\Attachment;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;

final class MailGazelleTransport extends AbstractTransport
{
    /**
     * Symfony refuses to send a message that has neither From nor Sender.
     * This address exists only to satisfy that check. It is not sent to the API.
     */
    private const SYMFONY_SENDER_PLACEHOLDER = 'mailgazelle-placeholder@example.com';

    /**
     * Header names the API ignores or that this transport maps itself.
     *
     * @var list<string>
     */
    private const SKIPPED_HEADERS = [
        'bcc',
        'cc',
        'content-type',
        'date',
        'from',
        'mailgazelle-idempotency-key',
        'mime-version',
        'reply-to',
        'return-path',
        'sender',
        'subject',
        'to',
    ];

    public function __construct(private readonly Client $client)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'mailgazelle';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email && $message->getFrom() === [] && $message->getSender() === null) {
            $message = clone $message;
            $message->sender(self::SYMFONY_SENDER_PLACEHOLDER);
        }

        return parent::send($message, $envelope);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $queued = $this->client->emails()->send($this->toSdkEmail($email));
        } catch (MailGazelleException $exception) {
            throw new TransportException(
                sprintf('Request to the Mail Gazelle API failed. Reason: %s', $exception->getMessage()),
                $exception->httpStatus(),
                $exception,
            );
        }

        $id = $queued->id();

        if ($id === '') {
            return;
        }

        $message->setMessageId($id);
        $this->rememberMessageId($message->getOriginalMessage(), $id);
    }

    private function toSdkEmail(Email $message): MailGazelleEmail
    {
        $to = $this->mapAddresses($message->getTo());

        if ($to === []) {
            throw new TransportException('A Mail Gazelle message requires at least one To address.');
        }

        $email = MailGazelleEmail::to($to[0]['email'], $to[0]['name']);

        foreach (array_slice($to, 1) as $address) {
            $email = $email->addTo($address['email'], $address['name']);
        }

        foreach ($this->mapAddresses($message->getCc()) as $address) {
            $email = $email->cc($address['email'], $address['name']);
        }

        foreach ($this->mapAddresses($message->getBcc()) as $address) {
            $email = $email->bcc($address['email'], $address['name']);
        }

        $email = $email->subject((string) $message->getSubject());

        $html = $message->getHtmlBody();

        if (is_string($html) && $html !== '') {
            $email = $email->html($html);
        }

        $text = $message->getTextBody();

        if (is_string($text) && $text !== '') {
            $email = $email->text($text);
        }

        $from = $message->getFrom();

        if ($from !== []) {
            $email = $email->from($from[0]->getAddress(), $this->name($from[0]));
        }

        foreach ($this->mapAddresses($message->getReplyTo()) as $address) {
            $email = $email->replyTo($address['email'], $address['name']);
        }

        $email = $this->applyHeaders($email, $message);

        foreach ($message->getAttachments() as $part) {
            $email = $email->attach($this->attachment($part));
        }

        return $email;
    }

    private function applyHeaders(MailGazelleEmail $email, Email $message): MailGazelleEmail
    {
        $tags = [];
        $headers = [];
        $idempotencyKey = null;

        foreach ($message->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $value = $header->getValue();
                $tags[$value] = $value;

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $tags[$header->getKey()] = $header->getValue();

                continue;
            }

            $name = $header->getName();

            if (strcasecmp($name, 'MailGazelle-Idempotency-Key') === 0) {
                $idempotencyKey = $header->getBodyAsString();

                continue;
            }

            if (in_array(strtolower($name), self::SKIPPED_HEADERS, true)) {
                continue;
            }

            $headers[$name] = $header->getBodyAsString();
        }

        foreach ($headers as $name => $value) {
            $email = $email->header($name, $value);
        }

        foreach ($tags as $key => $value) {
            $email = $email->tag((string) $key, (string) $value);
        }

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $email = $email->idempotencyKey($idempotencyKey);
        }

        return $email;
    }

    private function attachment(DataPart $part): Attachment
    {
        $filename = $part->getFilename() ?: 'attachment';
        $contentId = $part->getDisposition() === 'inline' ? $part->getContentId() : null;

        return Attachment::fromContents(
            $filename,
            $part->getBody(),
            $part->getContentType(),
            $contentId,
        );
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email: string, name: ?string}>
     */
    private function mapAddresses(array $addresses): array
    {
        $mapped = [];

        foreach ($addresses as $address) {
            $mapped[] = [
                'email' => $address->getAddress(),
                'name' => $this->name($address),
            ];
        }

        return $mapped;
    }

    private function name(Address $address): ?string
    {
        $name = trim($address->getName());

        return $name === '' ? null : $name;
    }

    private function rememberMessageId(RawMessage $message, string $id): void
    {
        if (! $message instanceof Email) {
            return;
        }

        $message->getHeaders()->addTextHeader('X-MailGazelle-Message-Id', $id);
    }
}
