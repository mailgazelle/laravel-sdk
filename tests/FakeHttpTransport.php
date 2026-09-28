<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Tests;

use MailGazelle\Http\Request;
use MailGazelle\Http\Response;
use MailGazelle\Http\TransportInterface;
use RuntimeException;

final class FakeHttpTransport implements TransportInterface
{
    /**
     * @var list<Request>
     */
    public array $requests = [];

    public function __construct(
        private readonly int $statusCode = 202,
        private readonly string $body = '{"id":"01JTEST","status":"queued"}',
    ) {}

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return new Response($this->statusCode, $this->body);
    }

    public function lastRequest(): Request
    {
        if ($this->requests === []) {
            throw new RuntimeException('No HTTP request was sent.');
        }

        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Request body was not a JSON object.');
        }

        return $decoded;
    }
}
