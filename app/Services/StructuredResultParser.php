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
        $trimmed = trim($output);

        foreach (array_reverse(preg_split('/\R/', $trimmed) ?: []) as $line) {
            $decoded = $this->decodeJsonObject($line);
            if ($decoded !== null && ! isset($decoded['type'])) {
                return $decoded;
            }

            $message = $decoded['item']['text'] ?? null;
            if (is_string($message) && ($payload = $this->decodeJsonObject($message)) !== null) {
                return $payload;
            }
        }

        // Harnesses such as Claude Code may return a single pretty-printed JSON
        // object (often inside a ```json fence) instead of the codex-style NDJSON
        // envelope handled above. Fall back to extracting that object directly.
        return $this->decodeFencedOrEmbeddedJsonObject($trimmed);
    }

    /** @return array<string, mixed>|null */
    private function decodeFencedOrEmbeddedJsonObject(string $output): ?array
    {
        $matchCount = preg_match_all('/```(?:json)?\s*(.*?)\s*```/s', $output, $matches);

        if (is_int($matchCount) && $matchCount > 0) {
            foreach (array_reverse($matches[1]) as $candidate) {
                if (($decoded = $this->decodeJsonObject($candidate)) !== null) {
                    return $decoded;
                }
            }
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return $this->decodeJsonObject(substr($output, $start, $end - $start + 1));
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonObject(string $value): ?array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }
}
