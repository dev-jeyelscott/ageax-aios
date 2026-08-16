import { useEffect, useRef } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type ConfigurationSnapshotAgent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    configuration_version: number;
};
export type ConfigurationSnapshotSkill = {
    id: number;
    slug: string;
    name: string;
    version: number;
    position: number;
};
export type ConfigurationSnapshot = {
    context_schema_version: number;
    context_hash: string;
    agent: ConfigurationSnapshotAgent;
    skills: ConfigurationSnapshotSkill[];
};
export type AgentRun = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    agent_messages: string[];
    exit_code: number | null;
    token_usage: number | null;
    started_at: string | null;
    finished_at: string | null;
    harness: string | null;
    external_run_id: string | null;
    context_schema_version: number | null;
    configuration_snapshot: ConfigurationSnapshot | null;
    task: { key: string; title: string } | null;
    worker: {
        role: string;
        status: string;
        last_heartbeat_at: string | null;
    } | null;
};

export function isAgentRunLive(agentRun: AgentRun): boolean {
    return agentRun.status === 'running';
}

/** Auto-scrolls a console container to the bottom while a run is still live. */
export function useAutoScrollConsole(
    live: boolean,
    dependency: unknown,
): React.RefObject<HTMLDivElement | null> {
    const consoleRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (live && consoleRef.current) {
            consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
        }
    }, [dependency, live]);

    return consoleRef;
}

export function AgentMessagesCard({
    agentRun,
    live,
    consoleRef,
}: {
    agentRun: AgentRun;
    live: boolean;
    consoleRef: React.RefObject<HTMLDivElement | null>;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Agent messages</CardTitle>
                <CardDescription>
                    {live
                        ? 'Live updates. Refreshes every two seconds.'
                        : 'Captured agent updates.'}
                </CardDescription>
            </CardHeader>
            <CardContent
                ref={consoleRef}
                className="max-h-[36rem] overflow-auto"
            >
                <div className="grid gap-3">
                    {agentRun.agent_messages.map((message, index) => (
                        <p
                            key={`${index}-${message}`}
                            className="rounded-md border border-success/30 bg-success/10 p-3 text-sm whitespace-pre-wrap text-success-foreground"
                        >
                            {message}
                        </p>
                    ))}
                    {agentRun.agent_messages.length === 0 && (
                        <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                            {live
                                ? 'Waiting for the agent’s first update.'
                                : 'This run did not produce an agent message.'}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export function ExecutionDetailsCard({ agentRun }: { agentRun: AgentRun }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Execution details</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm text-muted-foreground">
                <p>Attempt: {agentRun.attempt_number ?? '—'}</p>
                <p>Exit code: {agentRun.exit_code ?? 'Running'}</p>
                <p>
                    Token usage:{' '}
                    {agentRun.token_usage?.toLocaleString() ?? 'Unavailable'}
                </p>
                <p>Worker: {agentRun.worker?.status ?? 'Not recorded'}</p>
            </CardContent>
        </Card>
    );
}

export function ConfigurationEvidenceCard({
    agentRun,
}: {
    agentRun: AgentRun;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Configuration evidence</CardTitle>
                <CardDescription>
                    {agentRun.configuration_snapshot
                        ? 'Immutable snapshot captured at run start. Later Agent or Skill edits never change this record.'
                        : 'This run predates immutable configuration snapshots.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                {agentRun.configuration_snapshot ? (
                    <>
                        <div className="grid gap-1 text-muted-foreground">
                            <p>
                                Agent:{' '}
                                <span className="text-foreground">
                                    {agentRun.configuration_snapshot.agent.name}
                                </span>{' '}
                                (v
                                {
                                    agentRun.configuration_snapshot.agent
                                        .configuration_version
                                }
                                )
                            </p>
                            <p>
                                Role:{' '}
                                {agentRun.configuration_snapshot.agent.role.replace(
                                    '_',
                                    ' ',
                                )}
                            </p>
                            <p>
                                Harness:{' '}
                                {agentRun.harness ??
                                    agentRun.configuration_snapshot.agent
                                        .harness}
                            </p>
                            <p>
                                Model:{' '}
                                {agentRun.configuration_snapshot.agent.model ??
                                    'Harness default'}
                            </p>
                            <p>
                                Reasoning / effort:{' '}
                                {agentRun.configuration_snapshot.agent
                                    .reasoning_setting ?? 'Model default'}
                            </p>
                            <p>
                                Context schema version:{' '}
                                {
                                    agentRun.configuration_snapshot
                                        .context_schema_version
                                }
                            </p>
                            <p className="break-all">
                                Context hash:{' '}
                                {agentRun.configuration_snapshot.context_hash}
                            </p>
                            {agentRun.external_run_id && (
                                <p>
                                    External run ID: {agentRun.external_run_id}
                                </p>
                            )}
                        </div>
                        <div>
                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                Skills applied (
                                {agentRun.configuration_snapshot.skills.length})
                            </p>
                            {agentRun.configuration_snapshot.skills.length ===
                            0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No skills were assigned to this agent for
                                    this run.
                                </p>
                            ) : (
                                <ul className="grid gap-1">
                                    {agentRun.configuration_snapshot.skills.map(
                                        (skill) => (
                                            <li
                                                key={skill.id}
                                                className="flex items-center justify-between rounded-md border px-2 py-1 text-xs"
                                            >
                                                <span>
                                                    {skill.position + 1}.{' '}
                                                    {skill.name}
                                                </span>
                                                <Badge variant="outline">
                                                    v{skill.version}
                                                </Badge>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </div>
                    </>
                ) : (
                    <Badge variant="outline">Legacy run</Badge>
                )}
            </CardContent>
        </Card>
    );
}
