<?php

namespace App\Domain\Mcp;

use DateTimeInterface;

final class McpPayloadHasher
{
    public static function hash(mixed $payload): string
    {
        return hash('sha256', json_encode(
            self::normalise($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalise($item);
        }

        return $value;
    }
}
