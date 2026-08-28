<?php

namespace App\Services;

use App\AgentRole;
use Illuminate\Support\Str;

final class VoiceIntentRouter
{
    public const PROJECT_MANAGER_MESSAGE = 'project_manager_message';

    public const TASK_OPERATOR_MESSAGE = 'task_operator_message';

    public const OPEN_PROJECT = 'open_project';

    public const OPEN_TASK = 'open_task';

    public const LIST_TASKS = 'list_tasks';

    private const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Parse one confirmed transcript using only the explicit allowlisted voice grammar.
     *
     * @return array{
     *     type: string,
     *     body: ?string,
     *     task_key: ?string,
     *     recipient_role: ?AgentRole
     * }|null
     */
    public function parse(string $transcript): ?array
    {
        $transcript = trim($transcript);

        if ($transcript === '') {
            return null;
        }

        if (
            preg_match(
                '/\Amessage\s+project\s+manager\s*:\s*(.+)\z/is',
                $transcript,
                $matches,
            ) === 1
        ) {
            $body = trim($matches[1]);

            if (! $this->validMessageBody($body)) {
                return null;
            }

            return [
                'type' => self::PROJECT_MANAGER_MESSAGE,
                'body' => $body,
                'task_key' => null,
                'recipient_role' => null,
            ];
        }

        if (
            preg_match(
                '/\Amessage\s+task\s+([A-Za-z0-9._-]{1,255})\s+(coder|reviewer)\s*:\s*(.+)\z/is',
                $transcript,
                $matches,
            ) === 1
        ) {
            $body = trim($matches[3]);

            if (! $this->validMessageBody($body)) {
                return null;
            }

            return [
                'type' => self::TASK_OPERATOR_MESSAGE,
                'body' => $body,
                'task_key' => $matches[1],
                'recipient_role' => AgentRole::from(
                    strtolower($matches[2]),
                ),
            ];
        }

        if (
            preg_match(
                '/\Aopen\s+task\s+([A-Za-z0-9._-]{1,255})\z/i',
                $transcript,
                $matches,
            ) === 1
        ) {
            return [
                'type' => self::OPEN_TASK,
                'body' => null,
                'task_key' => $matches[1],
                'recipient_role' => null,
            ];
        }

        if (preg_match('/\Aopen\s+project\z/i', $transcript) === 1) {
            return [
                'type' => self::OPEN_PROJECT,
                'body' => null,
                'task_key' => null,
                'recipient_role' => null,
            ];
        }

        if (preg_match('/\A(?:show|list)\s+tasks\z/i', $transcript) === 1) {
            return [
                'type' => self::LIST_TASKS,
                'body' => null,
                'task_key' => null,
                'recipient_role' => null,
            ];
        }

        return null;
    }

    /**
     * Accept only non-empty message bodies that fit the existing message contract.
     */
    private function validMessageBody(string $body): bool
    {
        return $body !== ''
            && Str::length($body) <= self::MAX_MESSAGE_LENGTH;
    }
}
