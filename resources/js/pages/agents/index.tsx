import { Head, Link } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import { show } from '@/actions/App/Http/Controllers/GlobalAgentController';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Agent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    enabled: boolean;
    configuration_version: number;
    open_incident_count: number;
};

function roleLabel(role: string): string {
    return role.replace('_', ' ');
}

export default function AgentsIndex({ agents }: { agents: Agent[] }) {
    return (
        <>
            <Head title="Agents" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div>
                    <h1 className="text-2xl font-semibold">AIOS agents</h1>
                    <p className="text-muted-foreground">
                        Global, AIOS-level agents that operate on the AIOS
                        repository itself, not on any managed project. They are
                        seeded system identities and cannot be created or
                        deleted here.
                    </p>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    {agents.map((agent) => (
                        <Link
                            key={agent.id}
                            href={show(agent).url}
                            className="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <Card className="h-full transition-colors hover:bg-accent">
                                <CardHeader>
                                    <div className="flex items-center justify-between gap-4">
                                        <CardTitle className="flex items-center gap-2">
                                            <Bot className="size-5" />
                                            {agent.name}
                                        </CardTitle>
                                        <Badge
                                            variant={
                                                agent.enabled
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {agent.enabled
                                                ? 'enabled'
                                                : 'disabled'}
                                        </Badge>
                                    </div>
                                    <CardDescription>
                                        {roleLabel(agent.role)} ·{' '}
                                        {agent.harness}
                                        {agent.model
                                            ? ` · ${agent.model}`
                                            : ''}{' '}
                                        · v{agent.configuration_version}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="text-sm text-muted-foreground">
                                    {agent.open_incident_count} open recovery{' '}
                                    {agent.open_incident_count === 1
                                        ? 'incident'
                                        : 'incidents'}
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                    {agents.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-muted-foreground">
                                No global agents are configured yet.
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
