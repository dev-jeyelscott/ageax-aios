import { Float } from '@react-three/drei';
import { Canvas, useFrame } from '@react-three/fiber';
import { Suspense, useEffect, useRef, useState } from 'react';
import * as THREE from 'three';

function Bot({ working }: { working: boolean }) {
    const headRef = useRef<THREE.Group>(null);
    const bookRef = useRef<THREE.Group>(null);
    const pageGlowRef = useRef<THREE.Mesh>(null);
    const eyeLeftRef = useRef<THREE.Mesh>(null);
    const eyeRightRef = useRef<THREE.Mesh>(null);
    const antennaTipRef = useRef<THREE.Mesh>(null);

    useFrame((state) => {
        const t = state.clock.getElapsedTime();
        const pace = working ? 2.4 : 1;

        if (headRef.current) {
            headRef.current.rotation.y = Math.sin(t * 0.6) * 0.3;
            headRef.current.rotation.x = -0.28 + Math.sin(t * pace) * 0.03;
        }

        if (bookRef.current) {
            bookRef.current.position.y =
                0.02 * Math.sin(t * pace + 1);
        }

        const glowPulse = working
            ? 1.6 + Math.sin(t * 6) * 0.6
            : 0.9 + Math.sin(t * 1.5) * 0.25;

        for (const ref of [
            pageGlowRef,
            eyeLeftRef,
            eyeRightRef,
            antennaTipRef,
        ]) {
            if (ref.current) {
                const material =
                    ref.current.material as THREE.MeshStandardMaterial;
                material.emissiveIntensity = glowPulse;
            }
        }
    });

    return (
        <group position={[0, -0.08, 0]}>
            {/* Shoulders / chest */}
            <mesh position={[0, -0.32, 0]}>
                <capsuleGeometry args={[0.34, 0.22, 8, 16]} />
                <meshStandardMaterial
                    color="#1c3050"
                    metalness={0.65}
                    roughness={0.3}
                    emissive="#0891b2"
                    emissiveIntensity={0.25}
                />
            </mesh>

            {/* Neck */}
            <mesh position={[0, 0.04, 0]}>
                <cylinderGeometry args={[0.08, 0.1, 0.12, 16]} />
                <meshStandardMaterial
                    color="#0e1a2c"
                    metalness={0.7}
                    roughness={0.3}
                />
            </mesh>

            {/* Head */}
            <group ref={headRef} position={[0, 0.34, 0]}>
                <mesh>
                    <boxGeometry args={[0.42, 0.34, 0.36]} />
                    <meshStandardMaterial
                        color="#152438"
                        metalness={0.75}
                        roughness={0.2}
                    />
                </mesh>

                {/* Visor plate */}
                <mesh position={[0, 0, 0.185]}>
                    <boxGeometry args={[0.36, 0.17, 0.02]} />
                    <meshStandardMaterial
                        color="#0a1622"
                        metalness={0.4}
                        roughness={0.4}
                    />
                </mesh>

                {/* Eyes */}
                <mesh ref={eyeLeftRef} position={[-0.105, 0, 0.2]}>
                    <circleGeometry args={[0.05, 24]} />
                    <meshStandardMaterial
                        color="#7dd3fc"
                        emissive="#22d3ee"
                        emissiveIntensity={1.4}
                        toneMapped={false}
                    />
                </mesh>

                <mesh ref={eyeRightRef} position={[0.105, 0, 0.2]}>
                    <circleGeometry args={[0.05, 24]} />
                    <meshStandardMaterial
                        color="#7dd3fc"
                        emissive="#22d3ee"
                        emissiveIntensity={1.4}
                        toneMapped={false}
                    />
                </mesh>

                {/* Antenna */}
                <mesh position={[0, 0.23, 0]}>
                    <cylinderGeometry args={[0.013, 0.013, 0.17, 8]} />
                    <meshStandardMaterial
                        color="#334155"
                        metalness={0.6}
                        roughness={0.4}
                    />
                </mesh>

                <mesh ref={antennaTipRef} position={[0, 0.33, 0]}>
                    <sphereGeometry args={[0.038, 16, 16]} />
                    <meshStandardMaterial
                        color="#f0abfc"
                        emissive="#c084fc"
                        emissiveIntensity={1.2}
                        toneMapped={false}
                    />
                </mesh>
            </group>

            {/* Upper arms angled down to the book */}
            <mesh position={[-0.36, -0.18, 0.14]} rotation={[0.9, 0, 0.45]}>
                <capsuleGeometry args={[0.08, 0.24, 6, 12]} />
                <meshStandardMaterial
                    color="#16233a"
                    metalness={0.6}
                    roughness={0.4}
                />
            </mesh>

            <mesh position={[0.36, -0.18, 0.14]} rotation={[0.9, 0, -0.45]}>
                <capsuleGeometry args={[0.08, 0.24, 6, 12]} />
                <meshStandardMaterial
                    color="#16233a"
                    metalness={0.6}
                    roughness={0.4}
                />
            </mesh>

            {/* Forearms cradling the book from below */}
            <mesh position={[-0.24, -0.42, 0.42]} rotation={[0.2, 0.5, 0]}>
                <capsuleGeometry args={[0.065, 0.2, 6, 12]} />
                <meshStandardMaterial
                    color="#16233a"
                    metalness={0.6}
                    roughness={0.4}
                />
            </mesh>

            <mesh position={[0.24, -0.42, 0.42]} rotation={[0.2, -0.5, 0]}>
                <capsuleGeometry args={[0.065, 0.2, 6, 12]} />
                <meshStandardMaterial
                    color="#16233a"
                    metalness={0.6}
                    roughness={0.4}
                />
            </mesh>

            {/* Book, held open at chest height, tilted toward the camera */}
            <group
                ref={bookRef}
                position={[0, -0.34, 0.56]}
                rotation={[-0.55, 0, 0]}
            >
                <mesh position={[-0.21, 0, 0]} rotation={[0, 0.34, 0]}>
                    <boxGeometry args={[0.32, 0.025, 0.4]} />
                    <meshStandardMaterial
                        color="#334862"
                        metalness={0.25}
                        roughness={0.5}
                    />
                </mesh>

                <mesh position={[0.21, 0, 0]} rotation={[0, -0.34, 0]}>
                    <boxGeometry args={[0.32, 0.025, 0.4]} />
                    <meshStandardMaterial
                        color="#334862"
                        metalness={0.25}
                        roughness={0.5}
                    />
                </mesh>

                <mesh ref={pageGlowRef} position={[0, 0.018, 0]}>
                    <planeGeometry args={[0.52, 0.34]} />
                    <meshStandardMaterial
                        color="#a5f3fc"
                        emissive="#22d3ee"
                        emissiveIntensity={1}
                        toneMapped={false}
                        side={THREE.DoubleSide}
                    />
                </mesh>
            </group>
        </group>
    );
}

function usePrefersReducedMotion(): boolean {
    const [reduced, setReduced] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const listener = (event: MediaQueryListEvent) =>
            setReduced(event.matches);

        query.addEventListener('change', listener);

        return () => query.removeEventListener('change', listener);
    }, []);

    return reduced;
}

export function ProjectManagerBotVisual({
    working,
    label,
}: {
    working: boolean;
    label: string;
}) {
    const reducedMotion = usePrefersReducedMotion();

    if (reducedMotion) {
        return null;
    }

    return (
        <div role="img" aria-label={label} className="h-full w-full">
            <Canvas
                camera={{ position: [0, 0, 2.05], fov: 32 }}
                dpr={[1, 1.5]}
                gl={{ antialias: true, alpha: true }}
            >
                <ambientLight intensity={0.65} />
                <directionalLight position={[1.2, 1.8, 1.4]} intensity={1.1} />
                <pointLight position={[0.8, 0.6, 1.6]} intensity={1.4} color="#22d3ee" />
                <pointLight position={[-1, 0.2, 0.8]} intensity={0.5} color="#c084fc" />

                <Suspense fallback={null}>
                    <Float
                        speed={working ? 2.4 : 1}
                        rotationIntensity={0.1}
                        floatIntensity={0.25}
                    >
                        <Bot working={working} />
                    </Float>
                </Suspense>
            </Canvas>
        </div>
    );
}
