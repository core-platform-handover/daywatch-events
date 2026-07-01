<?php

namespace Laravel\Nightwatch\Concerns;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Illuminate\Support\Arr;

use function array_is_list;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strcut;
use function strlen;

/**
 * Shared payload serialization for the request/response bodies captured by the
 * request and outgoing-request sensors: redact configured fields, truncate
 * large collections to a handful of objects, then enforce a hard byte ceiling.
 *
 * @internal
 */
trait SerializesPayload
{
    /**
     * Decode JSON, redact configured fields, then bound the size: collections
     * (a top-level list or a `{data: [...]}` resource collection) are truncated
     * to a handful of objects; any other payload that is still over the limit
     * is cut at the hard byte ceiling. The byte ceiling is deliberately not
     * applied to object-truncated collections, whose size is already bounded by
     * the object count.
     *
     * @param  list<string>  $redactFields
     */
    protected function serializeJsonPayload(string $content, int $maxSize, int $maxObjects, array $redactFields): string
    {
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return $this->hardByteTruncate($content, $maxSize);
        }

        $decoded = $this->redactRecursively($decoded, $redactFields);

        if (strlen($content) <= $maxSize) {
            return $this->encode($decoded);
        }

        if ($this->isTruncatableCollection($decoded)) {
            return $this->encode($this->truncateResponseObjects($decoded, $maxObjects));
        }

        return $this->hardByteTruncate($this->encode($decoded), $maxSize);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function isTruncatableCollection(array $decoded): bool
    {
        return array_is_list($decoded)
            || (isset($decoded['data']) && is_array($decoded['data']) && array_is_list($decoded['data']));
    }

    /**
     * Final safety net: object-count truncation leaves single large objects
     * uncapped, so cut the serialized string at the byte ceiling. Cuts on a
     * UTF-8 boundary so we never emit a broken multibyte character.
     */
    protected function hardByteTruncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $truncated = strlen($value) - $maxBytes;

        return mb_strcut($value, 0, $maxBytes, 'UTF-8')."…[truncated {$truncated} bytes]";
    }

    /**
     * Lists are truncated to the first $maxObjects objects. Resource
     * collections (`{data: [...]}`) have the rule applied to `data`. Anything
     * else is kept whole.
     *
     * @param  array<mixed>  $decoded
     * @return array<mixed>
     */
    private function truncateResponseObjects(array $decoded, int $maxObjects): array
    {
        if (array_is_list($decoded)) {
            return $this->truncateList($decoded, $maxObjects);
        }

        if (isset($decoded['data']) && is_array($decoded['data']) && array_is_list($decoded['data'])) {
            $decoded['data'] = $this->truncateList($decoded['data'], $maxObjects);
        }

        return $decoded;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<mixed>
     */
    private function truncateList(array $list, int $maxObjects): array
    {
        if (count($list) <= $maxObjects) {
            return $list;
        }

        return [
            ...array_slice($list, 0, $maxObjects),
            ['more_truncated_objects' => count($list) - $maxObjects],
        ];
    }

    /**
     * @param  array<mixed>  $array
     * @param  list<string>  $redactFields
     * @return array<mixed>
     */
    protected function redactRecursively(array $array, array $redactFields): array
    {
        return Arr::map($array, function ($value, $key) use ($redactFields) {
            if (is_array($value)) {
                return $this->redactRecursively($value, $redactFields);
            }

            return ! in_array($key, $redactFields, true) || ! is_string($value) ? $value : '['.strlen($value).' bytes redacted]';
        });
    }
}
