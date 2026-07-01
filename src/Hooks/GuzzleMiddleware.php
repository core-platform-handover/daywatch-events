<?php

namespace Laravel\Nightwatch\Hooks;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * @internal
 */
final class GuzzleMiddleware
{
    /**
     * Hard ceiling on how many bytes we pull into memory to capture a body.
     * Bodies of unknown or larger size are skipped (only their size is
     * recorded); the sensor then truncates what we do capture to its own cap.
     */
    private const MAX_CAPTURE_BYTES = 5_000_000;

    /**
     * @param  Core<RequestState|CommandState>  $nightwatch
     */
    public function __construct(
        private Core $nightwatch,
    ) {
        //
    }

    /**
     * TODO record the failed responses as well.
     */
    public function __invoke(callable $handler): callable
    {
        if ($this->nightwatch->config['filtering']['ignore_outgoing_requests'] || $this->nightwatch->paused()) {
            return $handler;
        }

        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            try {
                $startMicrotime = $this->nightwatch->clock->microtime();
            } catch (Throwable $e) {
                $this->nightwatch->report($e, handled: true);

                return $handler($request, $options);
            }

            $captureBody = ($this->nightwatch->config['capture_outgoing_payload'] ?? false) === true;

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $startMicrotime, $captureBody): ResponseInterface {
                $requestBody = null;
                $responseBody = null;

                if ($captureBody) {
                    // Read after the handler runs so Guzzle's prepare_body
                    // middleware has materialized json/form bodies.
                    $requestBody = $this->captureRequestBody($request);
                    [$response, $responseBody] = $this->captureResponseBody($response);
                }

                try {
                    $endMicrotime = $this->nightwatch->clock->microtime();

                    $this->nightwatch->outgoingRequest(
                        $startMicrotime, $endMicrotime,
                        $request, $response,
                        $requestBody, $responseBody,
                    );
                } catch (Throwable $e) {
                    $this->nightwatch->report($e, handled: true);
                }

                return $response;
            });
        };
    }

    /**
     * Read the outgoing request body without disturbing the send. Only
     * bounded, known-size, seekable streams are read (so we can rewind);
     * anything else is skipped. Read failures are swallowed silently so a body
     * we cannot read never turns into an ingest write of its own.
     */
    private function captureRequestBody(RequestInterface $request): ?string
    {
        $stream = $request->getBody();

        if (! $this->isCapturable($stream) || ! $stream->isSeekable()) {
            return null;
        }

        try {
            $contents = (string) $stream;
            $stream->rewind();
        } catch (Throwable) {
            return null;
        }

        return $contents === '' ? null : $contents;
    }

    /**
     * Read the response body, leaving it readable for the caller. Seekable
     * bodies are rewound; a non-seekable body (already consumed) is re-wrapped
     * with a fresh in-memory stream so the application still receives it. Only
     * bounded, known-size bodies are read; failures are swallowed silently.
     *
     * @return array{0: ResponseInterface, 1: ?string}
     */
    private function captureResponseBody(ResponseInterface $response): array
    {
        $stream = $response->getBody();

        if (! $this->isCapturable($stream)) {
            return [$response, null];
        }

        try {
            $contents = (string) $stream;
        } catch (Throwable) {
            return [$response, null];
        }

        if ($stream->isSeekable()) {
            $stream->rewind();

            return [$response, $contents];
        }

        return [$response->withBody(Utils::streamFor($contents)), $contents];
    }

    /** Only capture bodies whose size is known and within the memory ceiling. */
    private function isCapturable(StreamInterface $stream): bool
    {
        $size = $stream->getSize();

        return $size !== null && $size > 0 && $size <= self::MAX_CAPTURE_BYTES;
    }
}
