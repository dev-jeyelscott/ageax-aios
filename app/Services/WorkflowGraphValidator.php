<?php

namespace App\Services;

use App\WorkflowStepKind;

/**
 * Deterministically validates a declarative workflow graph (steps + transitions) without ever
 * invoking an LLM, executing arbitrary code, or accepting an Agent-controlled repetition bound.
 *
 * A graph is a plain array: `entry` (a step key), `steps` (list of key/kind/label), and
 * `transitions` (list of from/to step key pairs). Validation never mutates persisted state; it is
 * a pure function over its input that always returns the same result for equivalent input.
 */
class WorkflowGraphValidator
{
    /**
     * The maximum number of distinct steps a single cyclic component may contain. This bound is a
     * fixed system constant, not part of the graph input schema, so no submitted graph and no
     * Agent can raise or otherwise control it.
     */
    private const int MaxCycleSteps = 10;

    /**
     * @param  array{
     *     entry?: mixed,
     *     steps?: mixed,
     *     transitions?: mixed,
     * }  $graph
     * @return array{
     *     valid: bool,
     *     errors: list<array{code: string, message: string, context: array<string, mixed>}>,
     *     canonical: ?array{entry: string, steps: list<array{key: string, kind: string, label: string}>, transitions: list<array{from: string, to: string}>},
     *     canonical_hash: ?string,
     * }
     */
    public function validate(array $graph): array
    {
        $errors = [];

        $entry = $graph['entry'] ?? null;
        $rawSteps = is_array($graph['steps'] ?? null) ? $graph['steps'] : [];
        $rawTransitions = is_array($graph['transitions'] ?? null) ? $graph['transitions'] : [];

        [$steps, $stepErrors] = $this->normalizeSteps($rawSteps);
        $errors = [...$errors, ...$stepErrors];

        [$transitions, $transitionErrors] = $this->normalizeTransitions($rawTransitions, $steps);
        $errors = [...$errors, ...$transitionErrors];

        if (! is_string($entry) || $entry === '') {
            $errors[] = $this->error('MISSING_ENTRY_STEP', 'The workflow graph must declare a non-empty entry step key.', []);
        } elseif (! array_key_exists($entry, $steps)) {
            $errors[] = $this->error('MISSING_ENTRY_STEP', "The entry step [{$entry}] is not declared among the graph steps.", ['entry' => $entry]);
        }

        $terminalKeys = $this->terminalStepKeys($steps);

        if ($terminalKeys === []) {
            $errors[] = $this->error('MISSING_TERMINAL_STEP', 'The workflow graph must declare at least one step with a terminal step kind.', []);
        }

        $adjacency = $this->adjacency($steps, $transitions);

        if (is_string($entry) && array_key_exists($entry, $steps)) {
            $reachable = $this->reachableFrom($entry, $adjacency);

            foreach (array_keys($steps) as $key) {
                if (! isset($reachable[$key])) {
                    $errors[] = $this->error('UNREACHABLE_STEP', "The step [{$key}] is not reachable from the entry step.", ['step' => $key]);
                }
            }

            $errors = [...$errors, ...$this->detectUnboundedCycles($steps, $adjacency, $terminalKeys)];
        }

        $errors = $this->sortErrors($errors);
        $valid = $errors === [];

        return [
            'valid' => $valid,
            'errors' => $errors,
            'canonical' => $valid ? $this->canonical($entry, $steps, $transitions) : null,
            'canonical_hash' => $valid ? $this->hash($this->canonical($entry, $steps, $transitions)) : null,
        ];
    }

    /**
     * @param  list<mixed>  $rawSteps
     * @return array{0: array<string, array{key: string, kind: string, label: string}>, 1: list<array{code: string, message: string, context: array<string, mixed>}>}
     */
    private function normalizeSteps(array $rawSteps): array
    {
        $steps = [];
        $errors = [];
        $allowedKinds = array_map(fn (WorkflowStepKind $kind): string => $kind->value, WorkflowStepKind::cases());
        $kindOwners = [];

        foreach ($rawSteps as $rawStep) {
            if (! is_array($rawStep) || ! is_string($rawStep['key'] ?? null) || $rawStep['key'] === '') {
                $errors[] = $this->error('INVALID_STEP_DECLARATION', 'Every workflow step must declare a non-empty string key.', ['step' => $rawStep]);

                continue;
            }

            $key = $rawStep['key'];
            $kind = $rawStep['kind'] ?? null;
            $label = is_string($rawStep['label'] ?? null) ? $rawStep['label'] : $key;

            if (array_key_exists($key, $steps)) {
                $errors[] = $this->error('DUPLICATE_STEP_KEY', "The step key [{$key}] is declared more than once.", ['step' => $key]);

                continue;
            }

            if (! is_string($kind) || ! in_array($kind, $allowedKinds, true)) {
                $errors[] = $this->error('DISALLOWED_STEP_KIND', "The step [{$key}] declares an unapproved step kind.", [
                    'step' => $key,
                    'kind' => $kind,
                ]);

                continue;
            }

            if (isset($kindOwners[$kind])) {
                $errors[] = $this->error('AMBIGUOUS_STEP_KIND_MAPPING', "The step kind [{$kind}] is mapped by more than one step ([{$kindOwners[$kind]}], [{$key}]).", [
                    'kind' => $kind,
                    'steps' => [$kindOwners[$kind], $key],
                ]);

                continue;
            }

            $kindOwners[$kind] = $key;
            $steps[$key] = ['key' => $key, 'kind' => $kind, 'label' => $label];
        }

        return [$steps, $errors];
    }

    /**
     * @param  list<mixed>  $rawTransitions
     * @param  array<string, array{key: string, kind: string, label: string}>  $steps
     * @return array{0: list<array{from: string, to: string}>, 1: list<array{code: string, message: string, context: array<string, mixed>}>}
     */
    private function normalizeTransitions(array $rawTransitions, array $steps): array
    {
        $transitions = [];
        $errors = [];
        $seen = [];

        foreach ($rawTransitions as $rawTransition) {
            $from = is_array($rawTransition) ? ($rawTransition['from'] ?? null) : null;
            $to = is_array($rawTransition) ? ($rawTransition['to'] ?? null) : null;

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                $errors[] = $this->error('INVALID_STEP_REFERENCE', 'Every workflow transition must declare non-empty from/to step keys.', ['transition' => $rawTransition]);

                continue;
            }

            if (! array_key_exists($from, $steps)) {
                $errors[] = $this->error('INVALID_STEP_REFERENCE', "The transition source [{$from}] does not reference a declared step.", ['step' => $from]);

                continue;
            }

            if (! array_key_exists($to, $steps)) {
                $errors[] = $this->error('INVALID_STEP_REFERENCE', "The transition target [{$to}] does not reference a declared step.", ['step' => $to]);

                continue;
            }

            $signature = "{$from}\0{$to}";

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $transitions[] = ['from' => $from, 'to' => $to];
        }

        return [$transitions, $errors];
    }

    /**
     * @param  array<string, array{key: string, kind: string, label: string}>  $steps
     * @return list<string>
     */
    private function terminalStepKeys(array $steps): array
    {
        $terminal = [];

        foreach ($steps as $key => $step) {
            if (WorkflowStepKind::from($step['kind'])->toTaskStatus()->isTerminal()) {
                $terminal[] = $key;
            }
        }

        return $terminal;
    }

    /**
     * @param  array<string, array{key: string, kind: string, label: string}>  $steps
     * @param  list<array{from: string, to: string}>  $transitions
     * @return array<string, list<string>>
     */
    private function adjacency(array $steps, array $transitions): array
    {
        $adjacency = array_fill_keys(array_keys($steps), []);

        foreach ($transitions as $transition) {
            $adjacency[$transition['from']][] = $transition['to'];
        }

        return $adjacency;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @return array<string, true>
     */
    private function reachableFrom(string $start, array $adjacency): array
    {
        $reachable = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reachable[$next])) {
                    $reachable[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reachable;
    }

    /**
     * Detect cyclic components that either cannot escape to a terminal step or exceed the fixed,
     * non-negotiable system cycle-size bound.
     *
     * @param  array<string, array{key: string, kind: string, label: string}>  $steps
     * @param  array<string, list<string>>  $adjacency
     * @param  list<string>  $terminalKeys
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    private function detectUnboundedCycles(array $steps, array $adjacency, array $terminalKeys): array
    {
        $errors = [];
        $terminalSet = array_fill_keys($terminalKeys, true);

        foreach ($this->stronglyConnectedComponents(array_keys($steps), $adjacency) as $component) {
            $isCyclic = count($component) > 1 || in_array($component[0], $adjacency[$component[0]] ?? [], true);

            if (! $isCyclic) {
                continue;
            }

            if (count($component) > self::MaxCycleSteps) {
                $errors[] = $this->error('UNBOUNDED_CYCLE_EXCEEDS_LIMIT', 'A cyclic component exceeds the fixed maximum bounded-cycle step limit.', [
                    'steps' => $this->sortedList($component),
                    'limit' => self::MaxCycleSteps,
                ]);

                continue;
            }

            $reachesTerminal = false;

            foreach ($component as $key) {
                if (isset($terminalSet[$key])) {
                    $reachesTerminal = true;

                    break;
                }
            }

            if (! $reachesTerminal) {
                $reachable = [];

                foreach ($component as $key) {
                    $reachable = [...$reachable, ...array_keys($this->reachableFrom($key, $adjacency))];
                }

                $reachesTerminal = array_intersect($reachable, $terminalKeys) !== [];
            }

            if (! $reachesTerminal) {
                $errors[] = $this->error('UNBOUNDED_CYCLE_NO_ESCAPE', 'A cyclic component has no reachable terminal step and would loop forever.', [
                    'steps' => $this->sortedList($component),
                ]);
            }
        }

        return $errors;
    }

    /**
     * Tarjan's strongly connected components algorithm, implemented iteratively so validation
     * remains deterministic and bounded for arbitrarily shaped input graphs.
     *
     * @param  list<string>  $nodes
     * @param  array<string, list<string>>  $adjacency
     * @return list<list<string>>
     */
    private function stronglyConnectedComponents(array $nodes, array $adjacency): array
    {
        $index = 0;
        $indices = [];
        $lowlink = [];
        $onStack = [];
        $stack = [];
        $components = [];

        $strongConnect = function (string $node) use (&$strongConnect, &$index, &$indices, &$lowlink, &$onStack, &$stack, &$components, $adjacency): void {
            $indices[$node] = $index;
            $lowlink[$node] = $index;
            $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach ($adjacency[$node] ?? [] as $successor) {
                if (! isset($indices[$successor])) {
                    $strongConnect($successor);
                    $lowlink[$node] = min($lowlink[$node], $lowlink[$successor]);
                } elseif (! empty($onStack[$successor])) {
                    $lowlink[$node] = min($lowlink[$node], $indices[$successor]);
                }
            }

            if ($lowlink[$node] === $indices[$node]) {
                $component = [];

                do {
                    $member = array_pop($stack);
                    $onStack[$member] = false;
                    $component[] = $member;
                } while ($member !== $node);

                $components[] = $component;
            }
        };

        foreach ($nodes as $node) {
            if (! isset($indices[$node])) {
                $strongConnect($node);
            }
        }

        return $components;
    }

    /**
     * @param  array<string, array{key: string, kind: string, label: string}>  $steps
     * @param  list<array{from: string, to: string}>  $transitions
     * @return array{entry: string, steps: list<array{key: string, kind: string, label: string}>, transitions: list<array{from: string, to: string}>}
     */
    private function canonical(mixed $entry, array $steps, array $transitions): array
    {
        $canonicalSteps = array_values($steps);
        usort($canonicalSteps, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $canonicalTransitions = array_map(
            fn (array $transition): array => ['from' => $transition['from'], 'to' => $transition['to']],
            $transitions,
        );
        usort($canonicalTransitions, fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return [
            'entry' => is_string($entry) ? $entry : '',
            'steps' => $canonicalSteps,
            'transitions' => $canonicalTransitions,
        ];
    }

    /**
     * @param  array{entry: string, steps: list<array{key: string, kind: string, label: string}>, transitions: list<array{from: string, to: string}>}  $canonical
     */
    private function hash(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, message: string, context: array<string, mixed>}
     */
    private function error(string $code, string $message, array $context): array
    {
        return ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $errors
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    private function sortErrors(array $errors): array
    {
        usort($errors, fn (array $a, array $b): int => [$a['code'], json_encode($a['context'])] <=> [$b['code'], json_encode($b['context'])]);

        return $errors;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedList(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
