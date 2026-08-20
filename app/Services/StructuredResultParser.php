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

        // A genuine ```json fence is authoritative: harnesses such as Claude Code CLI wrap
        // their complete structured answer in one. Checking it first avoids a pretty-printed
        // object's own single-line nested values (e.g. a one-line {"title":...,"rationale":...}
        // entry inside project_knowledge.architecture_decisions) being mistaken by the
        // codex-style NDJSON line scan below for the top-level event line.
        $fenced = $this->decodeFencedJsonObject($trimmed);
        if ($fenced !== null) {
            return $fenced;
        }

        // Claude Code CLI's `stream-json` output (used for every Claude Code execution, including
        // the Recovery Engineer/Reviewer/Project Manager roles) wraps the agent's final answer
        // inside an NDJSON envelope: {"type":"assistant","message":{"content":[{"type":"text",
        // "text":"...```json...```"}]}}. The fenced/embedded scans above operate on the raw
        // envelope text, where the agent's own JSON is still escaped as a string literal, so they
        // never match. Extract the actual message text first (mirroring
        // AgentRunRecorder::extractAgentMessageTexts(), which already handles both harnesses), then
        // re-run fenced/plain JSON extraction against that unwrapped text.
        foreach (array_reverse($this->extractAgentMessageTexts($trimmed)) as $text) {
            $decoded = $this->decodeFencedJsonObject($text) ?? $this->decodeJsonObject($text);
            if ($decoded !== null && ! isset($decoded['type'])) {
                return $decoded;
            }
        }

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

        // Last resort: a single pretty-printed JSON object with no fence at all.
        return $this->decodeEmbeddedJsonObject($trimmed);
    }

    /**
     * Recognizes the "agent message" shape from both supported harnesses: Codex CLI's
     * `item.completed` items and Claude Code CLI's `stream-json` assistant text blocks.
     *
     * @return list<string>
     */
    private function extractAgentMessageTexts(string $output): array
    {
        $texts = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = $this->decodeJsonObject($line);
            if ($event === null) {
                continue;
            }

            $item = $event['item'] ?? null;
            if (is_array($item) && ($item['type'] ?? null) === 'agent_message' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }

            $content = ($event['type'] ?? null) === 'assistant' ? $event['message']['content'] ?? null : null;
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                        $texts[] = $block['text'];
                    }
                }
            }
        }

        return $texts;
    }

    /** @return array<string, mixed>|null */
    private function decodeFencedJsonObject(string $output): ?array
    {
        $matchCount = preg_match_all('/```(?:json)?\s*(.*?)\s*```/s', $output, $matches);
        if (! is_int($matchCount) || $matchCount === 0) {
            return null;
        }

        foreach (array_reverse($matches[1]) as $candidate) {
            if (($decoded = $this->decodeJsonObject($candidate)) !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function decodeEmbeddedJsonObject(string $output): ?array
    {
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
