<?php

namespace App\Services;

use JsonException;

class StructuredResultParser
{
    /** @return array<string, mixed>|null */
    public function parse(string $output): ?array
    {
        $payload = $this->parseAgentMessage($output);

        return is_array($payload['phases'] ?? null) ? $payload : null;
    }

    /** @return array<string, mixed>|null */
    public function parseAgentMessage(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && ! isset($decoded['type'])) {
                    return $decoded;
                }

                $message = $decoded['item']['text'] ?? null;
                if (! is_string($message)) {
                    continue;
                }

                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($payload)) {
                    return $payload;
                }
            } catch (JsonException) {
            }
        }

        return null;
    }
}
