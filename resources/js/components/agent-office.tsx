import { Link } from '@inertiajs/react';
import { Float, Html, OrbitControls, Sparkles } from '@react-three/drei';
import { Canvas, useFrame } from '@react-three/fiber';
import {
    Activity,
    AlertTriangle,
    Bot,
    BrainCircuit,
    CircleDot,
    Code2,
    Coffee,
    Footprints,
    Orbit,
    Radio,
    ScanEye,
    ShieldCheck,
    Sparkles as SparklesIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type * as THREE from 'three';
import {
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import { Badge } from '@/components/ui/badge';

export type OfficeWorker = {
    id: number;
    role: string;
    status: string;
    last_heartbeat_at: string | null;
    lease_state: 'active' | 'expired' | 'none';
    run: {
        id: number;
        status: string;
        attempt_number: number | null;
        started_at: string;
        finished_at: string | null;
    } | null;
    task: { id: number; key: string; title: string; status: string } | null;
};

type AgentBehavior = 'walk' | 'think' | 'work' | 'rest' | 'brainstorm';

type OfficePresentation = {
    label: string;
    dotClass: string;
    textClass: string;
    color: string;
};

type FeaturedAgent = {
    worker: OfficeWorker;
    behavior: AgentBehavior;
    room: string;
    color: string;
    position: [number, number, number];
};

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Developer',
    reviewer: 'Reviewer',
};

const roleColors: Record<string, string> = {
    project_manager: '#a78bfa',
    coder: '#38bdf8',
    reviewer: '#34d399',
};

function supportsWebGL(): boolean {
    try {
        const canvas = document.createElement('canvas');

        return Boolean(
            canvas.getContext('webgl2') ?? canvas.getContext('webgl'),
        );
    } catch {
        return false;
    }
}

export function officePresentation(status: string): OfficePresentation {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                dotClass: 'bg-emerald-400',
                textClass: 'text-emerald-300',
                color: '#34d399',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                dotClass: 'bg-amber-400',
                textClass: 'text-amber-300',
                color: '#fbbf24',
            };
        case 'interrupted':
            return {
                label: 'Needs attention',
                dotClass: 'bg-rose-400',
                textClass: 'text-rose-300',
                color: '#fb7185',
            };
        case 'idle':
            return {
                label: 'Available',
                dotClass: 'bg-slate-400',
                textClass: 'text-slate-300',
                color: '#94a3b8',
            };
        default:
            return {
                label: 'Status unavailable',
                dotClass: 'bg-slate-500',
                textClass: 'text-slate-400',
                color: '#64748b',
            };
    }
}

function labelForRole(role: string): string {
    return (
        roleLabels[role] ??
        role
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase())
    );
}

function behaviorFor(worker: OfficeWorker): AgentBehavior {
    if (worker.status === 'recovering') {
        return 'rest';
    }

    if (worker.status === 'interrupted') {
        return 'think';
    }

    if (worker.status === 'idle') {
        return 'walk';
    }

    return worker.role === 'project_manager' ? 'brainstorm' : 'work';
}

function behaviorLabel(behavior: AgentBehavior): string {
    return {
        walk: 'Walking the floor',
        think: 'Thinking through a blocker',
        work: 'Building now',
        rest: 'Resting and recovering',
        brainstorm: 'Brainstorming the next move',
    }[behavior];
}

function buildFeaturedAgents(workers: OfficeWorker[]): FeaturedAgent[] {
    const roleOrder = ['project_manager', 'coder', 'reviewer'];
    const positions: [number, number, number][] = [
        [-4.15, 0.22, -1.15],
        [0, 0.22, -1.15],
        [4.15, 0.22, -1.15],
    ];
    const rooms = ['Strategy Room', 'Development Room', 'QA Room'];
    const claimedIds = new Set<number>();
    const selected = roleOrder.flatMap((role) => {
        const worker = workers.find(
            (candidate) =>
                candidate.role === role && !claimedIds.has(candidate.id),
        );

        if (worker) {
            claimedIds.add(worker.id);

            return [worker];
        }

        return [];
    });

    for (const worker of workers) {
        if (selected.length === 3) {
            break;
        }

        if (!claimedIds.has(worker.id)) {
            selected.push(worker);
            claimedIds.add(worker.id);
        }
    }

    return selected.slice(0, 3).map((worker, index) => ({
        worker,
        behavior: behaviorFor(worker),
        room: rooms[index],
        color: roleColors[worker.role] ?? '#94a3b8',
        position: positions[index],
    }));
}

function HoloConsole({ color = '#60a5fa' }: { color?: string }) {
    return (
        <group position={[0, 0.78, -0.9]}>
            <mesh castShadow>
                <boxGeometry args={[1.45, 0.88, 0.08]} />
                <meshStandardMaterial
                    color="#061226"
                    emissive={color}
                    emissiveIntensity={1.65}
                    metalness={0.65}
                    roughness={0.25}
                />
            </mesh>
            <mesh position={[0, -0.5, 0]}>
                <cylinderGeometry args={[0.035, 0.035, 0.9, 12]} />
                <meshStandardMaterial color="#172554" metalness={0.8} />
            </mesh>
            <mesh position={[0, -0.98, 0]} receiveShadow>
                <cylinderGeometry args={[0.42, 0.48, 0.1, 24]} />
                <meshStandardMaterial color="#0f172a" metalness={0.7} />
            </mesh>
        </group>
    );
}

function RoomPod({
    position,
    label,
    color,
    occupied,
}: {
    position: [number, number, number];
    label: string;
    color: string;
    occupied: boolean;
}) {
    return (
        <group position={position}>
            <mesh receiveShadow>
                <boxGeometry args={[3.55, 0.16, 2.95]} />
                <meshStandardMaterial
                    color="#182131"
                    metalness={0.62}
                    roughness={0.38}
                />
            </mesh>
            <mesh position={[0, 0.68, -1.43]}>
                <boxGeometry args={[3.55, 1.5, 0.08]} />
                <meshPhysicalMaterial
                    color="#243047"
                    emissive={color}
                    emissiveIntensity={0.16}
                    transparent
                    opacity={0.72}
                    roughness={0.12}
                    metalness={0.45}
                />
            </mesh>
            <mesh position={[-1.73, 0.64, 0]}>
                <boxGeometry args={[0.08, 1.25, 2.85]} />
                <meshStandardMaterial
                    color="#334155"
                    metalness={0.78}
                    roughness={0.22}
                />
            </mesh>
            <mesh position={[1.73, 0.64, 0]}>
                <boxGeometry args={[0.08, 1.25, 2.85]} />
                <meshStandardMaterial
                    color="#334155"
                    metalness={0.78}
                    roughness={0.22}
                />
            </mesh>
            <mesh position={[0, 0.13, 1.44]}>
                <boxGeometry args={[3.55, 0.09, 0.08]} />
                <meshStandardMaterial
                    color={color}
                    emissive={color}
                    emissiveIntensity={2.8}
                />
            </mesh>
            <mesh position={[0, 0.13, -1.42]}>
                <boxGeometry args={[3.55, 0.07, 0.05]} />
                <meshStandardMaterial
                    color="#f8d6a2"
                    emissive="#d97706"
                    emissiveIntensity={1.2}
                />
            </mesh>
            <HoloConsole color={color} />
            <pointLight
                position={[0, 1.25, 0]}
                color={color}
                intensity={2.2}
                distance={3.8}
            />
            <Html center position={[0, 1.72, -1.45]}>
                <div className="pointer-events-none w-38 rounded-md border border-white/15 bg-slate-950/90 px-2.5 py-1.5 text-center font-sans shadow-xl backdrop-blur">
                    <p className="text-[11px] font-semibold text-white">
                        {label}
                    </p>
                    <p className="mt-0.5 text-[9px] text-slate-400">
                        <span
                            className={`mr-1 inline-block size-1.5 rounded-full ${occupied ? 'bg-emerald-400' : 'bg-slate-500'}`}
                        />
                        {occupied ? 'Agent active' : 'Ready for assignment'}
                    </p>
                </div>
            </Html>
        </group>
    );
}

function HoloCore({ motionEnabled }: { motionEnabled: boolean }) {
    const core = useRef<THREE.Group>(null);

    useFrame(({ clock }) => {
        if (motionEnabled && core.current) {
            core.current.rotation.y = clock.getElapsedTime() * 0.35;
        }
    });

    return (
        <group ref={core} position={[0, 0.28, 3.3]}>
            <mesh receiveShadow>
                <cylinderGeometry args={[1.1, 1.28, 0.22, 48]} />
                <meshStandardMaterial
                    color="#111827"
                    metalness={0.9}
                    roughness={0.18}
                />
            </mesh>
            <mesh position={[0, 0.14, 0]}>
                <torusGeometry args={[0.82, 0.035, 12, 48]} />
                <meshStandardMaterial
                    color="#8b5cf6"
                    emissive="#8b5cf6"
                    emissiveIntensity={3}
                />
            </mesh>
            <mesh position={[0, 0.75, 0]}>
                <octahedronGeometry args={[0.55, 0]} />
                <meshPhysicalMaterial
                    color="#a78bfa"
                    emissive="#6d28d9"
                    emissiveIntensity={1.8}
                    transmission={0.25}
                    transparent
                    opacity={0.72}
                    metalness={0.3}
                    roughness={0.05}
                />
            </mesh>
            <mesh position={[0, 0.75, 0]}>
                <octahedronGeometry args={[0.74, 0]} />
                <meshBasicMaterial
                    color="#c4b5fd"
                    wireframe
                    transparent
                    opacity={0.48}
                />
            </mesh>
            <pointLight color="#8b5cf6" intensity={9} distance={5} />
        </group>
    );
}

function ThoughtOrbs({
    color,
    motionEnabled,
}: {
    color: string;
    motionEnabled: boolean;
}) {
    const orbit = useRef<THREE.Group>(null);

    useFrame(({ clock }) => {
        if (motionEnabled && orbit.current) {
            orbit.current.rotation.y = clock.getElapsedTime() * 1.8;
            orbit.current.rotation.z = Math.sin(clock.getElapsedTime()) * 0.35;
        }
    });

    return (
        <group ref={orbit} position={[0, 1.3, 0]}>
            {[0, (Math.PI * 2) / 3, (Math.PI * 4) / 3].map((rotation) => (
                <group key={rotation} rotation={[0, rotation, 0]}>
                    <mesh position={[0.62, 0, 0]}>
                        <sphereGeometry args={[0.075, 12, 12]} />
                        <meshStandardMaterial
                            color={color}
                            emissive={color}
                            emissiveIntensity={3}
                        />
                    </mesh>
                </group>
            ))}
        </group>
    );
}

function SceneAgent({
    agent,
    selected,
    motionEnabled,
    onSelect,
}: {
    agent: FeaturedAgent;
    selected: boolean;
    motionEnabled: boolean;
    onSelect: (worker: OfficeWorker) => void;
}) {
    const body = useRef<THREE.Group>(null);
    const leftArm = useRef<THREE.Group>(null);
    const rightArm = useRef<THREE.Group>(null);
    const { behavior, color, position, worker } = agent;

    useFrame(({ clock }) => {
        if (!motionEnabled || !body.current) {
            return;
        }

        const elapsed = clock.getElapsedTime() + worker.id;
        body.current.position.set(...position);
        body.current.position.y = position[1] + Math.sin(elapsed * 1.8) * 0.035;

        if (behavior === 'walk') {
            body.current.position.x =
                position[0] + Math.sin(elapsed * 0.7) * 0.72;
            body.current.position.z =
                position[2] + Math.cos(elapsed * 0.7) * 0.35;
            body.current.rotation.y = Math.cos(elapsed * 0.7) * 0.38;
        } else if (behavior === 'rest') {
            body.current.position.y =
                position[1] + 0.08 + Math.sin(elapsed * 1.1) * 0.07;
            body.current.rotation.z = Math.sin(elapsed * 0.65) * 0.1;
        } else {
            body.current.rotation.y = Math.sin(elapsed * 0.5) * 0.13;
        }

        const armMotion =
            behavior === 'work'
                ? Math.sin(elapsed * 7) * 0.48
                : behavior === 'brainstorm'
                  ? Math.sin(elapsed * 2.8) * 0.68
                  : behavior === 'walk'
                    ? Math.sin(elapsed * 3.5) * 0.52
                    : Math.sin(elapsed * 1.3) * 0.12;

        if (leftArm.current) {
            leftArm.current.rotation.x = armMotion;
        }

        if (rightArm.current) {
            rightArm.current.rotation.x = -armMotion;
        }
    });

    return (
        <group
            ref={body}
            position={position}
            scale={1.24}
            onClick={(event) => {
                event.stopPropagation();
                onSelect(worker);
            }}
        >
            <mesh position={[0, 0.02, 0]} receiveShadow>
                <cylinderGeometry args={[0.42, 0.55, 0.09, 32]} />
                <meshStandardMaterial
                    color="#020617"
                    metalness={0.85}
                    roughness={0.2}
                />
            </mesh>
            <mesh position={[0, 0.68, 0]} castShadow>
                <capsuleGeometry args={[0.28, 0.48, 8, 16]} />
                <meshStandardMaterial
                    color="#a8b4c8"
                    metalness={0.72}
                    roughness={0.18}
                />
            </mesh>
            <mesh position={[0, 0.7, 0.26]}>
                <capsuleGeometry args={[0.14, 0.21, 6, 12]} />
                <meshStandardMaterial
                    color="#07111f"
                    emissive={color}
                    emissiveIntensity={0.65}
                />
            </mesh>
            <mesh position={[0, 1.17, 0]} castShadow>
                <sphereGeometry args={[0.3, 28, 28]} />
                <meshStandardMaterial
                    color="#dbeafe"
                    metalness={0.62}
                    roughness={0.14}
                />
            </mesh>
            <mesh position={[0, 1.17, 0.245]}>
                <sphereGeometry args={[0.18, 20, 20]} />
                <meshStandardMaterial
                    color="#061226"
                    emissive={color}
                    emissiveIntensity={2.8}
                />
            </mesh>
            <group ref={leftArm} position={[-0.36, 0.8, 0]}>
                <mesh rotation={[0, 0, -0.25]} castShadow>
                    <capsuleGeometry args={[0.085, 0.34, 6, 12]} />
                    <meshStandardMaterial
                        color="#cbd5e1"
                        metalness={0.8}
                        roughness={0.2}
                    />
                </mesh>
            </group>
            <group ref={rightArm} position={[0.36, 0.8, 0]}>
                <mesh rotation={[0, 0, 0.25]} castShadow>
                    <capsuleGeometry args={[0.085, 0.34, 6, 12]} />
                    <meshStandardMaterial
                        color="#cbd5e1"
                        metalness={0.8}
                        roughness={0.2}
                    />
                </mesh>
            </group>
            <mesh position={[0, 0.76, 0.29]}>
                <circleGeometry args={[0.12, 24]} />
                <meshBasicMaterial color={color} />
            </mesh>
            <mesh position={[-0.14, 0.3, 0]} castShadow>
                <capsuleGeometry args={[0.08, 0.26, 6, 12]} />
                <meshStandardMaterial
                    color="#cbd5e1"
                    metalness={0.8}
                    roughness={0.2}
                />
            </mesh>
            <mesh position={[0.14, 0.3, 0]} castShadow>
                <capsuleGeometry args={[0.08, 0.26, 6, 12]} />
                <meshStandardMaterial
                    color="#cbd5e1"
                    metalness={0.8}
                    roughness={0.2}
                />
            </mesh>
            {(behavior === 'think' || behavior === 'brainstorm') && (
                <ThoughtOrbs color={color} motionEnabled={motionEnabled} />
            )}
            {behavior === 'rest' && motionEnabled && (
                <Float
                    speed={1.2}
                    rotationIntensity={0.12}
                    floatIntensity={0.25}
                >
                    <mesh position={[0.42, 1.5, 0]}>
                        <torusGeometry args={[0.2, 0.025, 10, 24]} />
                        <meshStandardMaterial
                            color="#fbbf24"
                            emissive="#fbbf24"
                            emissiveIntensity={2}
                        />
                    </mesh>
                </Float>
            )}
            {behavior === 'rest' && !motionEnabled && (
                <mesh position={[0.42, 1.5, 0]}>
                    <torusGeometry args={[0.2, 0.025, 10, 24]} />
                    <meshStandardMaterial
                        color="#fbbf24"
                        emissive="#fbbf24"
                        emissiveIntensity={2}
                    />
                </mesh>
            )}
            {selected && (
                <mesh position={[0, 0.08, 0]} rotation={[-Math.PI / 2, 0, 0]}>
                    <ringGeometry args={[0.58, 0.66, 48]} />
                    <meshBasicMaterial
                        color="#c4b5fd"
                        transparent
                        opacity={0.95}
                    />
                </mesh>
            )}
            <pointLight
                color={color}
                intensity={selected ? 4 : 2}
                distance={2.6}
            />
        </group>
    );
}

function OfficeScene({
    agents,
    selectedWorkerId,
    motionEnabled,
    onSelect,
}: {
    agents: FeaturedAgent[];
    selectedWorkerId: number | null;
    motionEnabled: boolean;
    onSelect: (worker: OfficeWorker) => void;
}) {
    return (
        <Canvas
            camera={{ position: [0, 8.6, 11.4], fov: 38 }}
            dpr={[1, 1.5]}
            frameloop={motionEnabled ? 'always' : 'demand'}
            shadows
        >
            <color attach="background" args={['#050816']} />
            <fog attach="fog" args={['#050816', 14, 26]} />
            <hemisphereLight args={['#dbeafe', '#111827', 1.25]} />
            <ambientLight intensity={1.35} />
            <directionalLight
                castShadow
                intensity={3.4}
                position={[-2, 10, 7]}
                shadow-mapSize={[2048, 2048]}
            />
            <pointLight
                color="#2563eb"
                intensity={5}
                position={[-6, 4, 1]}
                distance={10}
            />
            <pointLight
                color="#7c3aed"
                intensity={6}
                position={[0, 4, 4]}
                distance={9}
            />
            <mesh rotation={[-Math.PI / 2, 0, 0]} receiveShadow>
                <planeGeometry args={[18, 14]} />
                <meshStandardMaterial
                    color="#08111f"
                    metalness={0.75}
                    roughness={0.38}
                />
            </mesh>
            <gridHelper
                args={[18, 36, '#24324b', '#111b2e']}
                position={[0, 0.02, 0]}
            />
            <RoomPod
                position={[-4.15, 0.08, -1.7]}
                label="Strategy Room"
                color="#a78bfa"
                occupied={agents[0] !== undefined}
            />
            <RoomPod
                position={[0, 0.08, -1.7]}
                label="Development Room"
                color="#38bdf8"
                occupied={agents[1] !== undefined}
            />
            <RoomPod
                position={[4.15, 0.08, -1.7]}
                label="QA Room"
                color="#34d399"
                occupied={agents[2] !== undefined}
            />
            <RoomPod
                position={[-4.15, 0.08, 2.25]}
                label="Planning Room"
                color="#60a5fa"
                occupied={false}
            />
            <RoomPod
                position={[4.15, 0.08, 2.25]}
                label="Security Room"
                color="#fb7185"
                occupied={false}
            />
            <HoloCore motionEnabled={motionEnabled} />
            {agents.map((agent) => (
                <SceneAgent
                    key={agent.worker.id}
                    agent={agent}
                    selected={agent.worker.id === selectedWorkerId}
                    motionEnabled={motionEnabled}
                    onSelect={onSelect}
                />
            ))}
            <Sparkles
                count={90}
                scale={[14, 3, 10]}
                size={1.5}
                speed={motionEnabled ? 0.18 : 0}
                color="#93c5fd"
            />
            <OrbitControls
                enablePan={false}
                enableZoom={false}
                minPolarAngle={0.72}
                maxPolarAngle={1.08}
                target={[0, 0.35, 0.25]}
            />
        </Canvas>
    );
}

function FallbackOffice({
    agents,
    selectedWorkerId,
    onSelect,
}: {
    agents: FeaturedAgent[];
    selectedWorkerId: number | null;
    onSelect: (worker: OfficeWorker) => void;
}) {
    return (
        <div className="grid min-h-132 gap-3 bg-[radial-gradient(circle_at_50%_52%,rgba(124,58,237,0.28),transparent_17%),linear-gradient(140deg,#111827,#030712)] p-4 sm:grid-cols-3">
            {['Strategy Room', 'Development Room', 'QA Room'].map(
                (room, index) => {
                    const agent = agents[index];

                    return (
                        <div
                            key={room}
                            className="relative min-h-48 overflow-hidden rounded-xl border border-white/10 bg-slate-900/75 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]"
                        >
                            <div className="absolute inset-x-4 bottom-4 h-20 rounded-lg border border-sky-300/15 bg-[linear-gradient(135deg,rgba(14,165,233,0.12),transparent)]" />
                            <p className="relative text-sm font-semibold text-white">
                                {room}
                            </p>
                            <p className="relative mt-1 text-xs text-slate-400">
                                {agent
                                    ? behaviorLabel(agent.behavior)
                                    : 'Prepared for assignment'}
                            </p>
                            {agent && (
                                <button
                                    type="button"
                                    aria-pressed={
                                        agent.worker.id === selectedWorkerId
                                    }
                                    onClick={() => onSelect(agent.worker)}
                                    className={`relative mt-9 flex w-full items-center gap-3 rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none ${agent.worker.id === selectedWorkerId ? 'border-violet-300 bg-violet-400/15' : 'border-white/10 bg-slate-950/65 hover:bg-white/8'}`}
                                >
                                    <span
                                        className="grid size-12 place-items-center rounded-full border border-white/20 bg-slate-800 shadow-lg"
                                        style={{
                                            boxShadow: `0 0 22px ${agent.color}`,
                                        }}
                                    >
                                        <Bot
                                            className="size-6"
                                            style={{ color: agent.color }}
                                        />
                                    </span>
                                    <span>
                                        <span className="block text-sm font-medium text-white">
                                            {labelForRole(agent.worker.role)}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-slate-400">
                                            {behaviorLabel(agent.behavior)}
                                        </span>
                                    </span>
                                </button>
                            )}
                        </div>
                    );
                },
            )}
            <div className="col-span-full grid place-items-center rounded-xl border border-violet-300/25 bg-slate-950/70 py-5 text-center shadow-[0_0_34px_rgba(124,58,237,0.23)]">
                <ShieldCheck className="size-7 text-violet-300" />
                <p className="mt-2 text-sm font-semibold text-white">
                    AI Operating System
                </p>
                <p className="mt-1 text-xs text-slate-400">
                    Accessible office floor plan
                </p>
            </div>
        </div>
    );
}

function AgentInspector({
    agent,
    projectId,
}: {
    agent: FeaturedAgent;
    projectId: number;
}) {
    const presentation = officePresentation(agent.worker.status);
    const Icon = {
        walk: Footprints,
        think: BrainCircuit,
        work: Code2,
        rest: Coffee,
        brainstorm: SparklesIcon,
    }[agent.behavior];

    return (
        <aside className="rounded-xl border border-white/10 bg-slate-950/85 p-4 shadow-2xl backdrop-blur-xl">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-medium tracking-[0.16em] text-violet-300 uppercase">
                        Selected agent
                    </p>
                    <h3 className="mt-1 text-lg font-semibold text-white">
                        {labelForRole(agent.worker.role)}
                    </h3>
                </div>
                <Badge
                    className="border-white/10 bg-white/5 text-slate-200"
                    variant="outline"
                >
                    <span
                        className={`mr-1.5 size-1.5 rounded-full ${presentation.dotClass}`}
                    />
                    {presentation.label}
                </Badge>
            </div>
            <div className="mt-4 rounded-lg border border-violet-300/20 bg-violet-400/10 p-3">
                <div className="flex items-center gap-2 text-sm font-medium text-violet-100">
                    <Icon className="size-4" />
                    {behaviorLabel(agent.behavior)}
                </div>
                <p className="mt-1 text-xs text-violet-200/70">
                    {agent.room} · derived from the live worker status
                </p>
            </div>
            <dl className="mt-4 grid gap-3 text-sm">
                <div className="flex items-center justify-between border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Radio className="size-4" /> Lease
                    </dt>
                    <dd className="text-slate-200 capitalize">
                        {agent.worker.lease_state}
                    </dd>
                </div>
                <div className="flex items-center justify-between gap-3 border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Activity className="size-4" /> Heartbeat
                    </dt>
                    <dd className="text-right text-xs text-slate-200">
                        {agent.worker.last_heartbeat_at
                            ? new Date(
                                  agent.worker.last_heartbeat_at,
                              ).toLocaleString()
                            : 'Not recorded'}
                    </dd>
                </div>
            </dl>
            {agent.worker.task ? (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: agent.worker.task.id,
                        }).url
                    }
                    className="mt-4 block rounded-lg border border-cyan-300/20 bg-cyan-400/10 p-3 transition hover:border-cyan-200/50 hover:bg-cyan-400/15 focus-visible:ring-2 focus-visible:ring-cyan-200 focus-visible:outline-none"
                >
                    <span className="text-xs font-medium tracking-wide text-cyan-100 uppercase">
                        Current task · {agent.worker.task.status}
                    </span>
                    <p className="mt-1 text-sm font-medium text-white">
                        {agent.worker.task.key}: {agent.worker.task.title}
                    </p>
                </Link>
            ) : (
                <p className="mt-4 rounded-lg border border-dashed border-white/10 p-3 text-sm text-slate-400">
                    No task is assigned to this agent.
                </p>
            )}
            {agent.worker.run && (
                <Link
                    href={
                        showAgentRun({
                            project: projectId,
                            run: agent.worker.run.id,
                        }).url
                    }
                    className="mt-3 flex items-center justify-between rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none"
                >
                    <span>Run #{agent.worker.run.id}</span>
                    <span className="text-xs text-slate-500">
                        Attempt {agent.worker.run.attempt_number ?? '—'}
                    </span>
                </Link>
            )}
        </aside>
    );
}

function Metric({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: typeof Activity;
    label: string;
    value: number;
    tone: string;
}) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-sm shadow-lg backdrop-blur">
            <Icon className={`size-4 ${tone}`} />
            <span className="font-semibold text-white">{value}</span>
            <span className="text-slate-400">{label}</span>
        </div>
    );
}

export function AgentOffice({
    projectId,
    workers,
}: {
    projectId: number;
    workers: OfficeWorker[];
}) {
    const agents = useMemo(() => buildFeaturedAgents(workers), [workers]);
    const [selectedWorkerId, setSelectedWorkerId] = useState<number | null>(
        agents[0]?.worker.id ?? null,
    );
    const [sceneSupport, setSceneSupport] = useState<
        'checking' | 'available' | 'unavailable'
    >('checking');
    const [systemReducesMotion, setSystemReducesMotion] = useState(false);
    const [motionEnabled, setMotionEnabled] = useState(false);
    const selectedAgent =
        agents.find((agent) => agent.worker.id === selectedWorkerId) ??
        agents[0];
    const workingWorkers = workers.filter(
        (worker) => worker.status === 'working',
    ).length;
    const recoveringWorkers = workers.filter(
        (worker) => worker.status === 'recovering',
    ).length;
    const attentionWorkers = workers.filter(
        (worker) => worker.status === 'interrupted',
    ).length;

    useEffect(() => {
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        const updateCapability = (): void => {
            setSceneSupport(supportsWebGL() ? 'available' : 'unavailable');
            setSystemReducesMotion(media.matches);
            setMotionEnabled(!media.matches);
        };
        updateCapability();
        media.addEventListener('change', updateCapability);

        return () => media.removeEventListener('change', updateCapability);
    }, []);

    if (workers.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="agent-office-title"
            className="overflow-hidden rounded-2xl border border-slate-700/70 bg-slate-950 text-slate-100 shadow-2xl"
        >
            <div className="border-b border-white/8 bg-[linear-gradient(105deg,rgba(15,23,42,0.98),rgba(15,23,42,0.84),rgba(49,46,129,0.33))] px-5 py-5 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-medium text-violet-300">
                            <Bot className="size-4" /> Live AI organization
                        </div>
                        <h2
                            id="agent-office-title"
                            className="mt-1 text-2xl font-semibold tracking-tight text-white"
                        >
                            AI Engineering Office
                        </h2>
                        <p className="mt-1 text-sm text-slate-400">
                            A cinematic command floor for the three agents
                            currently moving this project forward.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Metric
                            icon={Activity}
                            label="agents online"
                            value={workingWorkers}
                            tone="text-emerald-400"
                        />
                        <Metric
                            icon={Orbit}
                            label="recovering"
                            value={recoveringWorkers}
                            tone="text-amber-400"
                        />
                        <Metric
                            icon={AlertTriangle}
                            label="need attention"
                            value={attentionWorkers}
                            tone="text-rose-400"
                        />
                    </div>
                </div>
            </div>
            <div className="grid gap-5 p-4 md:p-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="relative min-h-150 overflow-hidden rounded-xl border border-white/10 bg-slate-950 shadow-[inset_0_0_60px_rgba(15,23,42,0.85)]">
                    {sceneSupport === 'available' ? (
                        <OfficeScene
                            agents={agents}
                            selectedWorkerId={selectedAgent?.worker.id ?? null}
                            motionEnabled={motionEnabled}
                            onSelect={(worker) =>
                                setSelectedWorkerId(worker.id)
                            }
                        />
                    ) : sceneSupport === 'unavailable' ? (
                        <FallbackOffice
                            agents={agents}
                            selectedWorkerId={selectedAgent?.worker.id ?? null}
                            onSelect={(worker) =>
                                setSelectedWorkerId(worker.id)
                            }
                        />
                    ) : (
                        <div className="grid min-h-132 place-items-center bg-[radial-gradient(circle_at_center,rgba(124,58,237,0.2),transparent_35%)]">
                            <div className="text-center">
                                <Orbit className="mx-auto size-8 animate-spin text-violet-300 motion-reduce:animate-none" />
                                <p className="mt-3 text-sm font-medium text-slate-200">
                                    Initializing the 3D office…
                                </p>
                            </div>
                        </div>
                    )}
                    <div className="absolute right-3 bottom-3 flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2 text-xs text-slate-300 shadow-lg backdrop-blur">
                        <span className="mr-2 inline-block size-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399]" />
                        {sceneSupport === 'available'
                            ? motionEnabled
                                ? '3D office live'
                                : '3D office · motion paused'
                            : sceneSupport === 'checking'
                              ? 'Checking 3D support'
                              : '2D compatibility view'}
                        {sceneSupport === 'available' && (
                            <button
                                type="button"
                                onClick={() =>
                                    setMotionEnabled((value) => !value)
                                }
                                className="ml-1 rounded border border-violet-300/25 bg-violet-400/10 px-2 py-1 font-medium text-violet-200 transition hover:bg-violet-400/20 focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none"
                            >
                                {motionEnabled ? 'Pause motion' : 'Animate'}
                            </button>
                        )}
                    </div>
                </div>
                {selectedAgent && (
                    <AgentInspector
                        agent={selectedAgent}
                        projectId={projectId}
                    />
                )}
            </div>
            {systemReducesMotion && !motionEnabled && (
                <p className="sr-only">
                    Motion is paused because your system requests reduced
                    motion. Use the Animate button to enable it.
                </p>
            )}
            <div className="border-t border-white/8 bg-slate-950/80 px-5 py-4 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-100">
                        <ScanEye className="size-4 text-violet-300" /> Agent
                        states
                    </div>
                    <span className="text-xs text-slate-500">
                        Select an agent to inspect its real task, run,
                        heartbeat, and lease.
                    </span>
                </div>
                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    {agents.map((agent) => {
                        const Icon = {
                            walk: Footprints,
                            think: BrainCircuit,
                            work: Code2,
                            rest: Coffee,
                            brainstorm: SparklesIcon,
                        }[agent.behavior];
                        const isSelected =
                            agent.worker.id === selectedAgent?.worker.id;

                        return (
                            <button
                                key={agent.worker.id}
                                type="button"
                                aria-pressed={isSelected}
                                onClick={() =>
                                    setSelectedWorkerId(agent.worker.id)
                                }
                                className={`flex items-center gap-3 rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none ${isSelected ? 'border-violet-300/70 bg-violet-400/12' : 'border-white/8 bg-white/3 hover:bg-white/8'}`}
                            >
                                <span
                                    className="grid size-9 place-items-center rounded-lg border border-white/10 bg-slate-950"
                                    style={{ color: agent.color }}
                                >
                                    <Icon className="size-4" />
                                </span>
                                <span>
                                    <span className="block text-sm font-medium text-slate-100">
                                        {labelForRole(agent.worker.role)}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-slate-400">
                                        {behaviorLabel(agent.behavior)}
                                    </span>
                                </span>
                                <CircleDot
                                    className={`ml-auto size-4 ${officePresentation(agent.worker.status).textClass}`}
                                />
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
