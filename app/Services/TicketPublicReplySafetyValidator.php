<?php

namespace App\Services;

final class TicketPublicReplySafetyValidator
{
    private const SchemaVersion = 1;

    private const MinMatchingTokens = 8;

    private const MinMatchingCharacters = 48;

    /**
     * @param  array<array-key, mixed>  $questions
     * @param  array<array-key, mixed>  $blockers
     * @param  array<array-key, mixed>  $internalNotes
     * @return array<string, int|string|null>|null
     */
    public function unsafeEvidence(
        string $requesterReply,
        array $questions,
        array $blockers,
        string $finalPublicResponse,
        array $internalNotes,
    ): ?array {
        $candidates = [[
            'field' => 'requester_reply',
            'index' => null,
            'body' => $requesterReply,
        ]];

        foreach ($questions as $index => $question) {
            $candidates[] = [
                'field' => 'questions',
                'index' => $index,
                'body' => $question,
            ];
        }

        foreach ($blockers as $index => $blocker) {
            $candidates[] = [
                'field' => 'blockers',
                'index' => $index,
                'body' => $blocker,
            ];
        }

        $candidates[] = [
            'field' => 'final_public_response',
            'index' => null,
            'body' => $finalPublicResponse,
        ];

        foreach ($internalNotes as $internalNote) {
            if (
                ! is_array($internalNote)
                || ! $this->isProtectedInternalNote($internalNote)
            ) {
                continue;
            }

            $sourceBody = (string) $internalNote['body'];
            $sourceWindows = $this->windowHashes($sourceBody);

            if ($sourceWindows === []) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $candidateBody = $candidate['body'];

                if (! is_string($candidateBody)) {
                    continue;
                }

                $candidateWindows = $this->windowHashes($candidateBody);
                $matchedWindowHashes = array_intersect_key(
                    $sourceWindows,
                    $candidateWindows,
                );

                if ($matchedWindowHashes === []) {
                    continue;
                }

                $matchedWindowHash = (string) array_key_first(
                    $matchedWindowHashes,
                );

                return [
                    'schema_version' => self::SchemaVersion,
                    'reason' => 'internal_only_verbatim_overlap',
                    'candidate_field' => (string) $candidate['field'],
                    'candidate_index' => $candidate['index'],
                    'source_message_id' => is_int($internalNote['id'] ?? null)
                        ? $internalNote['id']
                        : null,
                    'source_body_hash' => hash('sha256', $sourceBody),
                    'candidate_body_hash' => hash(
                        'sha256',
                        $candidateBody,
                    ),
                    'matched_window_hash' => $matchedWindowHash,
                    'minimum_matching_tokens' => self::MinMatchingTokens,
                    'minimum_matching_characters' => self::MinMatchingCharacters,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $internalNote */
    private function isProtectedInternalNote(array $internalNote): bool
    {
        return ($internalNote['visibility'] ?? null) === 'internal'
            && ($internalNote['verbatim_public_reply_reuse_allowed'] ?? null) === false
            && is_string($internalNote['body'] ?? null)
            && trim((string) $internalNote['body']) !== '';
    }

    /** @return array<string, true> */
    private function windowHashes(string $body): array
    {
        $normalized = $this->normalize($body);

        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $normalized) ?: [];

        if (count($tokens) < self::MinMatchingTokens) {
            return [];
        }

        $hashes = [];
        $lastStart = count($tokens) - self::MinMatchingTokens;

        for ($start = 0; $start <= $lastStart; $start++) {
            $window = implode(
                ' ',
                array_slice(
                    $tokens,
                    $start,
                    self::MinMatchingTokens,
                ),
            );

            if ($this->characterLength($window) < self::MinMatchingCharacters) {
                continue;
            }

            $hashes[hash('sha256', $window)] = true;
        }

        return $hashes;
    }

    private function normalize(string $body): string
    {
        $normalized = $this->lower($body);
        $normalized = preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            $normalized,
        ) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return trim($normalized);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? \mb_strtolower($value)
            : strtolower($value);
    }

    private function characterLength(string $value): int
    {
        return function_exists('mb_strlen')
            ? \mb_strlen($value)
            : strlen($value);
    }
}
