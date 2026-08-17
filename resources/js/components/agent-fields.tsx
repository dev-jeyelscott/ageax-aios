import { useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

export type HarnessCapability = {
    configuration_fields: string[];
    models: string[];
    reasoning_settings: string[];
    reasoning_settings_by_model: Record<string, string[]>;
    execution_options: string[];
};

export type HarnessCapabilities = Record<string, HarnessCapability>;

export type AgentFieldsInitial = {
    name?: string;
    role?: string;
    harness?: string;
    model?: string | null;
    reasoning_setting?: string | null;
    default_context?: string | null;
    enabled?: boolean;
};

function inputClassName(hasError?: boolean): string {
    return `border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] ${
        hasError ? 'border-destructive' : ''
    }`;
}

export function selectClassName(hasError?: boolean): string {
    return `h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] ${
        hasError ? 'border-destructive' : ''
    }`;
}

/**
 * A role field is either editable (project Agents: choose among the core workflow roles) or
 * fixed (global Agents: the system role is a permanent identity, shown read-only).
 */
export type RoleField =
    | { editable: true; options: { value: string; label: string }[] }
    | { editable: false; label: string };

export function AgentFields({
    harnessCapabilities,
    initial,
    errors,
    roleField,
}: {
    harnessCapabilities: HarnessCapabilities;
    initial: AgentFieldsInitial;
    errors: Record<string, string | undefined>;
    roleField: RoleField;
}) {
    const [harness, setHarness] = useState(initial.harness ?? 'codex');
    const [model, setModel] = useState(initial.model ?? '');
    const [reasoning, setReasoning] = useState(initial.reasoning_setting ?? '');

    const capability = harnessCapabilities[harness];
    const models = capability?.models ?? [];
    const reasoningOptions = model
        ? (capability?.reasoning_settings_by_model[model] ?? [])
        : [];

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label htmlFor="name">Name</Label>
                <input
                    id="name"
                    name="name"
                    defaultValue={initial.name ?? ''}
                    required
                    className={inputClassName(Boolean(errors.name))}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="role">
                    {roleField.editable ? 'Workflow role' : 'System role'}
                </Label>

                {roleField.editable ? (
                    <>
                        <select
                            id="role"
                            name="role"
                            defaultValue={
                                initial.role ?? roleField.options[0]?.value
                            }
                            className={selectClassName(Boolean(errors.role))}
                        >
                            {roleField.options.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>

                        <InputError message={errors.role} />
                    </>
                ) : (
                    <p className="flex h-9 items-center px-3 text-sm text-muted-foreground">
                        {roleField.label} (fixed)
                    </p>
                )}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="harness">Harness</Label>

                <select
                    id="harness"
                    name="harness"
                    value={harness}
                    onChange={(event) => {
                        setHarness(event.target.value);
                        setModel('');
                        setReasoning('');
                    }}
                    className={selectClassName(Boolean(errors.harness))}
                >
                    {Object.keys(harnessCapabilities).map((identifier) => (
                        <option key={identifier} value={identifier}>
                            {identifier}
                        </option>
                    ))}
                </select>

                <InputError message={errors.harness} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="model">Model</Label>

                <select
                    id="model"
                    name="model"
                    value={model}
                    onChange={(event) => {
                        setModel(event.target.value);
                        setReasoning('');
                    }}
                    className={selectClassName(Boolean(errors.model))}
                >
                    <option value="">Harness default</option>

                    {models.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>

                <InputError message={errors.model} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="reasoning_setting">Reasoning / effort</Label>

                <select
                    id="reasoning_setting"
                    name="reasoning_setting"
                    value={reasoning}
                    onChange={(event) => setReasoning(event.target.value)}
                    disabled={!model}
                    className={selectClassName(
                        Boolean(errors.reasoning_setting),
                    )}
                >
                    <option value="">Model default</option>

                    {reasoningOptions.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>

                <InputError message={errors.reasoning_setting} />
            </div>

            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="default_context">Default context</Label>

                <textarea
                    id="default_context"
                    name="default_context"
                    rows={3}
                    defaultValue={initial.default_context ?? ''}
                    className="w-full rounded-md border bg-background p-3 text-sm"
                    placeholder="Deterministic guidance provided to every run of this agent."
                />

                <InputError message={errors.default_context} />
            </div>

            <input
                type="hidden"
                name="enabled"
                value={(initial.enabled ?? true) ? '1' : '0'}
            />
        </div>
    );
}
