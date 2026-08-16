import { Canvas, useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';

export type RobotAnimationState =
    | 'idle'
    | 'working'
    | 'thinking'
    | 'reviewing'
    | 'success'
    | 'failed'
    | 'interrupted';

type Side = 'left' | 'right';
type Vector3Tuple = [number, number, number];
type PartProps = {
    material: THREE.Material;
    position?: Vector3Tuple;
    rotation?: Vector3Tuple;
    scale?: Vector3Tuple;
};

const boxGeometry = new THREE.BoxGeometry(1, 1, 1);
const sphereGeometry = new THREE.SphereGeometry(0.5, 12, 8);
const cylinderGeometry = new THREE.CylinderGeometry(0.5, 0.5, 1, 12);
const torusGeometry = new THREE.TorusGeometry(0.5, 0.08, 8, 18);

const armorMaterial = new THREE.MeshStandardMaterial({
    color: '#edf2f7',
    metalness: 0.72,
    roughness: 0.24,
});
const silverMaterial = new THREE.MeshStandardMaterial({
    color: '#9aa7b8',
    metalness: 0.82,
    roughness: 0.22,
});
const mechanicalMaterial = new THREE.MeshStandardMaterial({
    color: '#151b24',
    metalness: 0.78,
    roughness: 0.3,
    flatShading: true,
});
const visorMaterial = new THREE.MeshStandardMaterial({
    color: '#020617',
    metalness: 0.45,
    roughness: 0.18,
});
const energyMaterial = new THREE.MeshStandardMaterial({
    color: '#67e8f9',
    emissive: '#22d3ee',
    emissiveIntensity: 2.1,
    metalness: 0.2,
    roughness: 0.2,
    toneMapped: false,
});

const roleAccentColors: Record<string, string> = {
    project_manager: '#c084fc',
    coder: '#38bdf8',
    reviewer: '#fbbf24',
};
const stateColors: Record<RobotAnimationState, string> = {
    idle: '#67e8f9',
    working: '#22d3ee',
    thinking: '#a78bfa',
    reviewing: '#fbbf24',
    success: '#34d399',
    failed: '#fb7185',
    interrupted: '#f59e0b',
};

function BoxPart({ material, position, rotation, scale }: PartProps) {
    return (
        <mesh
            geometry={boxGeometry}
            material={material}
            position={position}
            rotation={rotation}
            scale={scale}
        />
    );
}

function SpherePart({ material, position, rotation, scale }: PartProps) {
    return (
        <mesh
            geometry={sphereGeometry}
            material={material}
            position={position}
            rotation={rotation}
            scale={scale}
        />
    );
}

function CylinderPart({ material, position, rotation, scale }: PartProps) {
    return (
        <mesh
            geometry={cylinderGeometry}
            material={material}
            position={position}
            rotation={rotation}
            scale={scale}
        />
    );
}

function TorusPart({ material, position, rotation, scale }: PartProps) {
    return (
        <mesh
            geometry={torusGeometry}
            material={material}
            position={position}
            rotation={rotation}
            scale={scale}
        />
    );
}

function headPose(state: RobotAnimationState): Vector3Tuple {
    switch (state) {
        case 'thinking':
            return [0.08, -0.18, -0.04];
        case 'reviewing':
            return [-0.04, 0, 0];
        case 'working':
            return [-0.12, 0, 0];
        case 'success':
            return [-0.1, 0, 0];
        case 'failed':
            return [0.3, 0, 0];
        case 'interrupted':
            return [0.08, 0.12, 0];
        default:
            return [0, 0, 0];
    }
}

function armPose(
    state: RobotAnimationState,
    side: Side,
): { shoulder: Vector3Tuple; elbow: Vector3Tuple; wrist: Vector3Tuple } {
    const sign = side === 'left' ? -1 : 1;

    switch (state) {
        case 'working':
            return {
                shoulder: [-0.72, 0, sign * 0.08],
                elbow: [-0.88, 0, -sign * 0.08],
                wrist: [0.08, 0, sign * 0.08],
            };
        case 'thinking':
            return side === 'right'
                ? {
                      shoulder: [-0.28, 0, 0.34],
                      elbow: [-0.72, 0, -0.28],
                      wrist: [0.1, 0.08, 0.12],
                  }
                : {
                      shoulder: [0.04, 0, -0.05],
                      elbow: [-0.08, 0, 0],
                      wrist: [0, 0, 0],
                  };
        case 'reviewing':
            return {
                shoulder: [side === 'left' ? -0.32 : -0.18, 0, sign * 0.14],
                elbow: [side === 'left' ? -0.46 : -0.52, 0, -sign * 0.1],
                wrist: [0, -sign * 0.08, sign * 0.06],
            };
        case 'success':
            return {
                shoulder: [0, 0, sign * 0.16],
                elbow: [-0.12, 0, 0],
                wrist: [0, 0, 0],
            };
        case 'failed':
            return {
                shoulder: [0.18, 0, -sign * 0.08],
                elbow: [-0.04, 0, 0],
                wrist: [0, 0, 0],
            };
        case 'interrupted':
            return {
                shoulder: [0.08, 0, sign * 0.08],
                elbow: [-0.16, 0, 0],
                wrist: [0, 0, 0],
            };
        default:
            return {
                shoulder: [0.06, 0, sign * 0.04],
                elbow: [-0.12, 0, -sign * 0.02],
                wrist: [0, 0, 0],
            };
    }
}

function legPose(state: RobotAnimationState): {
    hip: Vector3Tuple;
    knee: Vector3Tuple;
    ankle: Vector3Tuple;
} {
    if (state === 'failed') {
        return {
            hip: [0.04, 0, 0],
            knee: [0.08, 0, 0],
            ankle: [-0.04, 0, 0],
        };
    }

    return {
        hip: [0, 0, 0],
        knee: [0, 0, 0],
        ankle: [0, 0, 0],
    };
}

function RobotHead({
    state,
    statusMaterial,
    roleMaterial,
    animated,
}: {
    state: RobotAnimationState;
    statusMaterial: THREE.MeshStandardMaterial;
    roleMaterial: THREE.MeshStandardMaterial;
    animated: boolean;
}) {
    const headRef = useRef<THREE.Group>(null);
    const scanRef = useRef<THREE.Mesh>(null);
    const pose = headPose(state);

    useFrame((frame) => {
        if (!animated || !headRef.current) {
            return;
        }

        const t = frame.clock.getElapsedTime();
        const scan =
            state === 'reviewing'
                ? Math.sin(t * 1.7) * 0.24
                : state === 'thinking'
                  ? Math.sin(t * 1.1) * 0.12
                  : state === 'interrupted'
                    ? Math.sin(t * 7) * 0.035
                    : Math.sin(t * 0.65) * 0.035;

        headRef.current.rotation.set(pose[0], pose[1] + scan, pose[2]);

        if (scanRef.current) {
            const scanning = ['working', 'thinking', 'reviewing'].includes(state);
            scanRef.current.position.x = scanning ? Math.sin(t * 2.7) * 0.24 : 0;
        }
    });

    return (
        <group ref={headRef} position={[0, 1.03, 0]} rotation={pose}>
            <BoxPart material={armorMaterial} scale={[0.88, 0.58, 0.62]} />
            <BoxPart
                material={silverMaterial}
                position={[0, -0.21, 0.18]}
                scale={[0.72, 0.12, 0.28]}
            />
            <BoxPart
                material={visorMaterial}
                position={[0, 0.02, 0.323]}
                scale={[0.7, 0.25, 0.035]}
            />
            <BoxPart
                material={energyMaterial}
                position={[0, 0.02, 0.347]}
                scale={[0.48, 0.04, 0.02]}
            />
            <mesh
                ref={scanRef}
                geometry={boxGeometry}
                material={statusMaterial}
                position={[0, 0.02, 0.354]}
                scale={[0.055, 0.18, 0.018]}
            />
            {[-0.46, 0.46].map((x) => (
                <TorusPart
                    key={x}
                    material={roleMaterial}
                    position={[x, 0.01, 0]}
                    rotation={[0, Math.PI / 2, 0]}
                    scale={[0.25, 0.25, 0.25]}
                />
            ))}
        </group>
    );
}

function RobotHand({ material }: { material: THREE.Material }) {
    return (
        <group>
            <BoxPart material={armorMaterial} scale={[0.3, 0.24, 0.3]} />
            <BoxPart
                material={material}
                position={[0, 0.01, 0.165]}
                scale={[0.2, 0.06, 0.035]}
            />
            {[-0.09, 0, 0.09].map((x) => (
                <BoxPart
                    key={x}
                    material={mechanicalMaterial}
                    position={[x, -0.18, 0.04]}
                    scale={[0.055, 0.22, 0.08]}
                />
            ))}
        </group>
    );
}

function RobotArm({
    side,
    state,
    statusMaterial,
    roleMaterial,
    animated,
}: {
    side: Side;
    state: RobotAnimationState;
    statusMaterial: THREE.MeshStandardMaterial;
    roleMaterial: THREE.MeshStandardMaterial;
    animated: boolean;
}) {
    const shoulderRef = useRef<THREE.Group>(null);
    const elbowRef = useRef<THREE.Group>(null);
    const wristRef = useRef<THREE.Group>(null);
    const pose = armPose(state, side);
    const sign = side === 'left' ? -1 : 1;

    useFrame((frame) => {
        if (!animated) {
            return;
        }

        shoulderRef.current?.rotation.set(...pose.shoulder);
        elbowRef.current?.rotation.set(...pose.elbow);
        wristRef.current?.rotation.set(...pose.wrist);

        if (state === 'working' && wristRef.current) {
            wristRef.current.rotation.z += Math.sin(frame.clock.elapsedTime * 9) * 0.08 * sign;
        }

        if (state === 'thinking' && side === 'right' && wristRef.current) {
            wristRef.current.rotation.y += Math.sin(frame.clock.elapsedTime * 1.8) * 0.08;
        }
    });

    return (
        <group
            ref={shoulderRef}
            position={[sign * 0.87, 0.29, 0]}
            rotation={pose.shoulder}
        >
            <SpherePart material={mechanicalMaterial} scale={[0.38, 0.38, 0.38]} />
            <TorusPart material={energyMaterial} scale={[0.48, 0.48, 0.48]} />
            <BoxPart
                material={armorMaterial}
                position={[0, -0.37, 0]}
                scale={[0.34, 0.6, 0.38]}
            />
            <BoxPart
                material={roleMaterial}
                position={[0, -0.32, 0.205]}
                scale={[0.11, 0.34, 0.035]}
            />

            <group
                ref={elbowRef}
                position={[0, -0.74, 0]}
                rotation={pose.elbow}
            >
                <SpherePart material={mechanicalMaterial} scale={[0.29, 0.29, 0.29]} />
                <TorusPart material={energyMaterial} scale={[0.35, 0.35, 0.35]} />
                <BoxPart
                    material={armorMaterial}
                    position={[0, -0.34, 0]}
                    scale={[0.3, 0.54, 0.34]}
                />
                <BoxPart
                    material={energyMaterial}
                    position={[0, -0.34, 0.18]}
                    scale={[0.08, 0.34, 0.035]}
                />

                <group
                    ref={wristRef}
                    position={[0, -0.67, 0]}
                    rotation={pose.wrist}
                >
                    <CylinderPart material={mechanicalMaterial} scale={[0.22, 0.18, 0.22]} />
                    <TorusPart
                        material={energyMaterial}
                        rotation={[Math.PI / 2, 0, 0]}
                        scale={[0.27, 0.27, 0.27]}
                    />
                    <group position={[0, -0.25, 0]}>
                        <RobotHand material={statusMaterial} />
                    </group>
                </group>
            </group>
        </group>
    );
}

function RobotLeg({
    side,
    state,
    statusMaterial,
    animated,
}: {
    side: Side;
    state: RobotAnimationState;
    statusMaterial: THREE.MeshStandardMaterial;
    animated: boolean;
}) {
    const hipRef = useRef<THREE.Group>(null);
    const kneeRef = useRef<THREE.Group>(null);
    const ankleRef = useRef<THREE.Group>(null);
    const pose = legPose(state);
    const sign = side === 'left' ? -1 : 1;

    useFrame(() => {
        if (!animated) {
            return;
        }

        hipRef.current?.rotation.set(...pose.hip);
        kneeRef.current?.rotation.set(...pose.knee);
        ankleRef.current?.rotation.set(...pose.ankle);
    });

    return (
        <group
            ref={hipRef}
            position={[sign * 0.36, -0.28, 0]}
            rotation={pose.hip}
        >
            <SpherePart material={mechanicalMaterial} scale={[0.33, 0.33, 0.33]} />
            <TorusPart material={energyMaterial} scale={[0.4, 0.4, 0.4]} />
            <BoxPart
                material={armorMaterial}
                position={[0, -0.49, 0]}
                scale={[0.38, 0.78, 0.44]}
            />
            <BoxPart
                material={energyMaterial}
                position={[0, -0.46, 0.235]}
                scale={[0.09, 0.4, 0.035]}
            />

            <group ref={kneeRef} position={[0, -0.94, 0]} rotation={pose.knee}>
                <SpherePart material={mechanicalMaterial} scale={[0.3, 0.3, 0.3]} />
                <TorusPart material={statusMaterial} scale={[0.36, 0.36, 0.36]} />
                <BoxPart
                    material={armorMaterial}
                    position={[0, -0.49, 0]}
                    scale={[0.36, 0.78, 0.42]}
                />
                <BoxPart
                    material={energyMaterial}
                    position={[0, -0.49, 0.225]}
                    scale={[0.08, 0.4, 0.035]}
                />

                <group
                    ref={ankleRef}
                    position={[0, -0.93, 0]}
                    rotation={pose.ankle}
                >
                    <CylinderPart material={mechanicalMaterial} scale={[0.22, 0.2, 0.22]} />
                    <BoxPart
                        material={armorMaterial}
                        position={[0, -0.19, 0.12]}
                        scale={[0.42, 0.26, 0.68]}
                    />
                    <BoxPart
                        material={energyMaterial}
                        position={[0, -0.17, 0.47]}
                        scale={[0.26, 0.055, 0.04]}
                    />
                </group>
            </group>
        </group>
    );
}

function RobotModel({
    role,
    state,
    animated,
}: {
    role: string;
    state: RobotAnimationState;
    animated: boolean;
}) {
    const rootRef = useRef<THREE.Group>(null);
    const torsoRef = useRef<THREE.Group>(null);
    const roleMaterial = useMemo(() => {
        const color = roleAccentColors[role] ?? '#67e8f9';

        return new THREE.MeshStandardMaterial({
            color,
            emissive: color,
            emissiveIntensity: 1.4,
            metalness: 0.3,
            roughness: 0.22,
            toneMapped: false,
        });
    }, [role]);
    const statusMaterial = useMemo(() => {
        const color = stateColors[state];

        return new THREE.MeshStandardMaterial({
            color,
            emissive: color,
            emissiveIntensity: 1.8,
            metalness: 0.2,
            roughness: 0.18,
            toneMapped: false,
        });
    }, [state]);

    useEffect(() => () => roleMaterial.dispose(), [roleMaterial]);
    useEffect(() => () => statusMaterial.dispose(), [statusMaterial]);

    useFrame((frame) => {
        if (!animated) {
            return;
        }

        const t = frame.clock.getElapsedTime();

        if (rootRef.current) {
            rootRef.current.position.y = Math.sin(t * 1.2) * 0.012;
        }

        if (torsoRef.current) {
            torsoRef.current.scale.y =
                1 + Math.sin(t * 1.8) * (state === 'failed' ? 0.004 : 0.012);
        }

        const pace =
            state === 'working' || state === 'reviewing'
                ? 5.5
                : state === 'interrupted'
                  ? 7
                  : 2.2;

        statusMaterial.emissiveIntensity =
            1.55 + ((Math.sin(t * pace) + 1) / 2) * 1.25;
    });

    return (
        <group ref={rootRef} dispose={null}>
            <group ref={torsoRef} position={[0, 0.72, 0]}>
                <BoxPart material={armorMaterial} scale={[1.35, 0.98, 0.58]} />
                <BoxPart
                    material={silverMaterial}
                    position={[0, -0.04, 0.305]}
                    scale={[0.76, 0.56, 0.045]}
                />
                <BoxPart
                    material={mechanicalMaterial}
                    position={[0, -0.18, 0.338]}
                    scale={[0.5, 0.24, 0.035]}
                />
                <BoxPart
                    material={statusMaterial}
                    position={[0, 0.11, 0.342]}
                    scale={[0.42, 0.075, 0.04]}
                />
                <BoxPart
                    material={energyMaterial}
                    position={[0, -0.05, 0.348]}
                    scale={[0.26, 0.035, 0.04]}
                />
                <BoxPart
                    material={roleMaterial}
                    position={[0, -0.31, 0.344]}
                    scale={[0.28, 0.045, 0.04]}
                />
                <CylinderPart
                    material={mechanicalMaterial}
                    position={[0, -0.69, 0]}
                    scale={[0.22, 0.34, 0.22]}
                />
                <TorusPart
                    material={energyMaterial}
                    position={[0, -0.63, 0]}
                    rotation={[Math.PI / 2, 0, 0]}
                    scale={[0.42, 0.42, 0.42]}
                />
                <CylinderPart
                    material={mechanicalMaterial}
                    position={[0, 0.62, 0]}
                    scale={[0.18, 0.2, 0.18]}
                />

                <RobotHead
                    state={state}
                    statusMaterial={statusMaterial}
                    roleMaterial={roleMaterial}
                    animated={animated}
                />
                <RobotArm
                    side="left"
                    state={state}
                    statusMaterial={statusMaterial}
                    roleMaterial={roleMaterial}
                    animated={animated}
                />
                <RobotArm
                    side="right"
                    state={state}
                    statusMaterial={statusMaterial}
                    roleMaterial={roleMaterial}
                    animated={animated}
                />
            </group>

            <BoxPart
                material={armorMaterial}
                position={[0, -0.03, 0]}
                scale={[0.9, 0.42, 0.5]}
            />
            <BoxPart
                material={mechanicalMaterial}
                position={[0, 0.01, 0.27]}
                scale={[0.48, 0.2, 0.035]}
            />
            <BoxPart
                material={energyMaterial}
                position={[0, -0.09, 0.292]}
                scale={[0.28, 0.045, 0.025]}
            />
            <RobotLeg
                side="left"
                state={state}
                statusMaterial={statusMaterial}
                animated={animated}
            />
            <RobotLeg
                side="right"
                state={state}
                statusMaterial={statusMaterial}
                animated={animated}
            />
        </group>
    );
}

function usePrefersReducedMotion(): boolean {
    const [reducedMotion, setReducedMotion] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const updatePreference = (event: MediaQueryListEvent) => {
            setReducedMotion(event.matches);
        };

        query.addEventListener('change', updatePreference);

        return () => query.removeEventListener('change', updatePreference);
    }, []);

    return reducedMotion;
}

function useElementVisibility() {
    const elementRef = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const element = elementRef.current;

        if (!element) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => setVisible(entry?.isIntersecting ?? true),
            { rootMargin: '80px' },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return { elementRef, visible };
}

export function AgeaxRobotVisual({
    role,
    state,
    label,
}: {
    role: string;
    state: RobotAnimationState;
    label: string;
}) {
    const reducedMotion = usePrefersReducedMotion();
    const { elementRef, visible } = useElementVisibility();
    const animated = visible && !reducedMotion;

    return (
        <div
            ref={elementRef}
            role="img"
            aria-label={label}
            className="h-full w-full"
        >
            <Canvas
                camera={{
                    position: [0, 0.08, 10.4],
                    fov: 27,
                    near: 0.1,
                    far: 40,
                }}
                dpr={[1, 1.25]}
                frameloop={animated ? 'always' : 'demand'}
                gl={{
                    alpha: true,
                    antialias: true,
                    powerPreference: 'high-performance',
                }}
            >
                <ambientLight intensity={0.9} />
                <directionalLight
                    position={[2.4, 3.5, 4.5]}
                    intensity={1.7}
                />
                <pointLight
                    position={[-2.2, 0.8, 3.5]}
                    color="#22d3ee"
                    intensity={1.1}
                />
                <RobotModel role={role} state={state} animated={animated} />
            </Canvas>
        </div>
    );
}
