import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { show as showAgent } from '@/actions/App/Http/Controllers/GlobalAgentController';
import {
    AgentMessagesCard,
    ConfigurationEvidenceCard,
    ExecutionDetailsCard,
    isAgentRunLive,
    useAutoScrollConsole,
} from '@/components/agent-run-console';
import type { AgentRun } from '@/components/agent-run-console';
import { Badge } from '@/components/ui/badge';

type Agent = { id: number; name: string; role: string };

export default function AgentRunShow({
    agent,
    agent_run: agentRun,
}: {
    agent: Agent;
    agent_run: AgentRun;
}) {
    usePoll(2_000, { only: ['agent_run'] }, { mode: 'rest' });
    const live = isAgentRunLive(agentRun);
    const consoleRef = useAutoScrollConsole(live, agentRun.agent_messages);

    return (
        <>
            <Head title={`${agent.name} run`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link
                            href={showAgent(agent.id).url}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" /> {agent.name}
                        </Link>
                        <h1 className="text-2xl font-semibold">
                            {agent.name} run
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {agentRun.task
                                ? `${agentRun.task.key}: ${agentRun.task.title}`
                                : 'AIOS-level execution'}
                        </p>
                    </div>
                    <Badge variant={live ? 'default' : 'secondary'}>
                        {agentRun.status}
                    </Badge>
                </div>

                <AgentMessagesCard
                    agentRun={agentRun}
                    live={live}
                    consoleRef={consoleRef}
                />

                <ExecutionDetailsCard agentRun={agentRun} />

                <ConfigurationEvidenceCard agentRun={agentRun} />
            </div>
        </>
    );
}
