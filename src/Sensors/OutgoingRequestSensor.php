<?php

namespace Laravel\Nightwatch\Sensors;

use Laravel\Nightwatch\Concerns\SerializesPayload;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\Types\Str;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function hash;
use function is_numeric;
use function round;
use function str_contains;
use function strlen;

/**
 * @internal
 */
final class OutgoingRequestSensor
{
    use SerializesPayload;

    /**
     * @param  list<string>  $redactPayloadFields
     */
    public function __construct(
        private RequestState|CommandState $executionState,
        private bool $capturePayload = false,
        private int $requestPayloadMaxSize = 16384,
        private int $responsePayloadMaxSize = 16384,
        private int $responsePayloadMaxObjects = 10,
        private array $redactPayloadFields = [],
    ) {
        //
    }

    /**
     * @return array{0: OutgoingRequest, 1: callable(): array<mixed>}
     */
    public function __invoke(float $startMicrotime, float $endMicrotime, RequestInterface $request, ResponseInterface $response, ?string $requestBody = null, ?string $responseBody = null): array
    {
        $duration = (int) round(($endMicrotime - $startMicrotime) * 1_000_000);
        $uri = $request->getUri()->withUserInfo('', null);

        return [
            $record = new OutgoingRequest(
                method: $request->getMethod(),
                url: (string) $uri,
                duration: $duration,
                requestSize: $this->resolveMessageSize($request) ?? 0,
                responseSize: $this->resolveMessageSize($response) ?? 0,
                statusCode: $response->getStatusCode(),
            ),
            function () use ($startMicrotime, $uri, $record, $request, $response, $requestBody, $responseBody) {
                $this->executionState->outgoingRequests++;

                return [
                    'v' => 1,
                    't' => 'outgoing-request',
                    'timestamp' => $startMicrotime,
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => hash('xxh128', $uri->getHost()),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    // --- //
                    'host' => Str::tinyText($uri->getHost()),
                    'method' => Str::tinyText($record->method),
                    'url' => Str::text($record->url),
                    'duration' => $record->duration,
                    'request_size' => $record->requestSize,
                    'response_size' => $record->responseSize,
                    'status_code' => $record->statusCode,
                    'payload' => Str::mediumText($this->serializeBody($requestBody, $request->getHeaderLine('Content-Type'), $this->requestPayloadMaxSize)),
                    'response_payload' => Str::mediumText($this->serializeBody($responseBody, $response->getHeaderLine('Content-Type'), $this->responsePayloadMaxSize)),
                ];
            },
        ];
    }

    /**
     * Serialize a captured request/response body: JSON is redacted +
     * object-truncated, other text is byte-capped, binary is replaced with a
     * size marker. Falls back to raw byte-truncation on any JSON failure.
     */
    private function serializeBody(?string $body, string $contentType, int $maxSize): string
    {
        if (! $this->capturePayload || $body === null || $body === '') {
            return '';
        }

        if ($this->isBinaryContentType($contentType)) {
            return '"[binary '.strlen($body).' bytes]"';
        }

        if (str_contains($contentType, 'json')) {
            try {
                return $this->serializeJsonPayload($body, $maxSize, $this->responsePayloadMaxObjects, $this->redactPayloadFields);
            } catch (Throwable) {
                // Malformed JSON — fall through to raw truncation.
            }
        }

        return $this->hardByteTruncate($body, $maxSize);
    }

    private function isBinaryContentType(string $contentType): bool
    {
        foreach (['image/', 'audio/', 'video/', 'multipart/form-data', 'application/octet-stream', 'application/pdf', 'application/zip', 'application/gzip'] as $needle) {
            if (str_contains($contentType, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveMessageSize(MessageInterface $message): ?int
    {
        $size = $message->getBody()->getSize();

        if ($size !== null) {
            return $size;
        }

        $length = $message->getHeader('content-length')[0] ?? null;

        if (is_numeric($length)) {
            return (int) $length;
        }

        return null;
    }
}
