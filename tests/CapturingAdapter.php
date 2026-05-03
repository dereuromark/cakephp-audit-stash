<?php

declare(strict_types=1);

namespace AuditStash\Test;

use Cake\Http\Client\AdapterInterface;
use Cake\Http\Client\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Test adapter that records each request it receives and returns canned
 * responses in order. Used by the channel tests in place of the bundled
 * `Cake\Http\Client\Adapter\Mock`, whose `match`-closure capture pattern
 * isn't reliable on the prefer-lowest CI pin of cakephp/http.
 */
class CapturingAdapter implements AdapterInterface
{
    /**
     * Last request the channel sent through this adapter.
     */
    public ?RequestInterface $captured = null;

    /**
     * Full ordered list of every request seen — useful for retry tests.
     *
     * @var list<\Psr\Http\Message\RequestInterface>
     */
    public array $requests = [];

    /**
     * @var list<\Cake\Http\Client\Response>
     */
    private array $responses;

    public function __construct(Response ...$responses)
    {
        $this->responses = array_values($responses);
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param array<string, mixed> $options
     *
     * @return array<\Cake\Http\Client\Response>
     */
    public function send(RequestInterface $request, array $options): array
    {
        $this->captured = $request;
        $this->requests[] = $request;

        $response = array_shift($this->responses);
        if ($response === null) {
            $response = new Response(['HTTP/1.1 200 OK'], '');
        }

        return [$response];
    }
}
