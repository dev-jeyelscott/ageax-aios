import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, CheckCircle2 } from 'lucide-react';
import {
    index as agentsIndex,
    showRun,
    update as updateAgent,
} from '@/actions/App/Http/Controllers/GlobalAgentController';
import { AgentFields } from '@/components/agent-fields';
import type { HarnessCapabilities } from '@/components/agent-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    reasoning_setting: string | null;
    default_context: string | null;
    enabled: boolean;
    configuration_version: number;
};

type Incident = {
    id: number;
    status: string;
    root_cause_category: string | null;
    detected_at: string;
    resolved_at: string | null;
    escalation_reason: string | null;
    project: { id: number; name: string } | null;
    task: { key: string; title: string; project_id: number } | null;
    latest_run_id: number | null;
};

type IncidentPage = {
    data: Incident[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

function roleLabel(role: string): string {
    return role.replace('_', ' ');
}

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    if (status === 'recovered') {
        return 'default';
    }

    if (status === 'escalated') {
        return 'secondary';
    }

    return 'outline';
}

export default function AgentShow({
    agent,
    incidents,
    harnessCapabilities,
}: {
    agent: Agent;
    incidents: IncidentPage;
    harnessCapabilities: HarnessCapabilities;
}) {
    return (
        <>
            <Head title={agent.name} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div>
                    <Link
                        href={agentsIndex().url}
                        className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> Agents
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">{agent.name}</h1>
                        <Badge
                            variant={agent.enabled ? 'default' : 'secondary'}
                        >
                            {agent.enabled ? 'enabled' : 'disabled'}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {roleLabel(agent.role)} system agent · v
                        {agent.configuration_version}. This is a global,
                        AIOS-level agent — it cannot be deleted, and it never
                        operates on a managed project's repository.
                    </p>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                        <div>
                            <CardTitle>Configuration</CardTitle>
                            <CardDescription>
                                Editing this takes effect on the next scheduled
                                run.
                            </CardDescription>
                        </div>
                        <ToggleEnabledForm agent={agent} />
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...updateAgent.form(agent)}
                            className="grid gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <AgentFields
                                        harnessCapabilities={
                                            harnessCapabilities
                                        }
                                        initial={agent}
                                        errors={errors}
                                        roleField={{
                                            editable: false,
                                            label: roleLabel(agent.role),
                                        }}
                                    />
                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save changes
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recovery incidents</CardTitle>
                        <CardDescription>
                            What this agent has worked, across every project.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        {incidents.data.length === 0 && (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No recovery incidents recorded yet.
                            </p>
                        )}
                        {incidents.data.map((incident) => (
                            <div
                                key={incident.id}
                                className="grid gap-1 rounded-md border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {incident.project?.name ??
                                            'Unknown project'}
                                        {incident.task
                                            ? ` · ${incident.task.key}: ${incident.task.title}`
                                            : ''}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        {incident.root_cause_category && (
                                            <Badge variant="outline">
                                                {incident.root_cause_category.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={statusVariant(
                                                incident.status,
                                            )}
                                        >
                                            {incident.status}
                                        </Badge>
                                    </div>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Detected {incident.detected_at}
                                    {incident.resolved_at
                                        ? ` · Resolved ${incident.resolved_at}`
                                        : ''}
                                </p>
                                {incident.escalation_reason && (
                                    <p className="text-xs text-muted-foreground">
                                        {incident.escalation_reason}
                                    </p>
                                )}
                                {incident.latest_run_id && (
                                    <Link
                                        href={
                                            showRun([
                                                agent.id,
                                                incident.latest_run_id,
                                            ]).url
                                        }
                                        className="text-xs text-primary hover:underline"
                                    >
                                        View console
                                    </Link>
                                )}
                            </div>
                        ))}
                        {(incidents.prev_page_url ||
                            incidents.next_page_url) && (
                            <div className="flex justify-between pt-2">
                                {incidents.prev_page_url ? (
                                    <Link
                                        href={incidents.prev_page_url}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        ← Newer
                                    </Link>
                                ) : (
                                    <span />
                                )}
                                {incidents.next_page_url && (
                                    <Link
                                        href={incidents.next_page_url}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        Older →
                                    </Link>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function ToggleEnabledForm({ agent }: { agent: Agent }) {
    return (
        <Form {...updateAgent.form(agent)} className="inline">
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="name" value={agent.name} />
                    <input type="hidden" name="harness" value={agent.harness} />
                    <input
                        type="hidden"
                        name="model"
                        value={agent.model ?? ''}
                    />
                    <input
                        type="hidden"
                        name="reasoning_setting"
                        value={agent.reasoning_setting ?? ''}
                    />
                    <input
                        type="hidden"
                        name="default_context"
                        value={agent.default_context ?? ''}
                    />
                    <input
                        type="hidden"
                        name="enabled"
                        value={agent.enabled ? '0' : '1'}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        title={
                            errors.enabled ??
                            (agent.enabled ? 'Disable agent' : 'Enable agent')
                        }
                    >
                        {agent.enabled ? <Ban /> : <CheckCircle2 />}
                        {agent.enabled ? 'Disable' : 'Enable'}
                    </Button>
                </>
            )}
        </Form>
    );
}
