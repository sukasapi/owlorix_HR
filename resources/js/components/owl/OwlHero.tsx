import { useT } from '@/lib/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { OwlController } from './Owl3D';
import { OwlEyes, type EyeState } from './OwlEyes';

const SWITCH_KEY = 'owlorix.owl3d';
const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
// A tab hidden this long gives its WebGL context back; the owl is rebuilt when the tab returns.
const RELEASE_AFTER_MS = 60_000;

function readSwitch(): boolean {
    try {
        return window.localStorage.getItem(SWITCH_KEY) !== 'off';
    } catch {
        return true;
    }
}

function writeSwitch(on: boolean) {
    try {
        if (on) window.localStorage.removeItem(SWITCH_KEY);
        else window.localStorage.setItem(SWITCH_KEY, 'off');
    } catch {
        // Storage blocked (private mode, policy): the choice holds until the page reloads.
    }
}

let webglUsable: boolean | undefined;

/**
 * three.js needs WebGL 2. failIfMajorPerformanceCaveat lets the browser refuse a context it would
 * run on a slow software path, which would load the CPU of a studio PC that is busy rendering.
 */
function canUseWebGL(): boolean {
    if (webglUsable === undefined) {
        try {
            const gl = document.createElement('canvas').getContext('webgl2', { failIfMajorPerformanceCaveat: true });
            webglUsable = gl !== null;
            gl?.getExtension('WEBGL_lose_context')?.loseContext();
        } catch {
            webglUsable = false;
        }
    }
    return webglUsable;
}

function useReducedMotion(): boolean {
    const [reduced, setReduced] = useState(() => window.matchMedia(REDUCED_MOTION).matches);
    useEffect(() => {
        const query = window.matchMedia(REDUCED_MOTION);
        const onChange = () => setReduced(query.matches);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, []);
    return reduced;
}

function useReleasedWhileHidden(): boolean {
    const [released, setReleased] = useState(false);
    useEffect(() => {
        let timer = 0;
        const onVisibility = () => {
            window.clearTimeout(timer);
            if (document.hidden) timer = window.setTimeout(() => setReleased(true), RELEASE_AFTER_MS);
            else setReleased(false);
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, []);
    return released;
}

interface CanvasProps {
    state: EyeState;
    onReady: () => void;
    onFail: () => void;
}

/**
 * Loads three.js as its own chunk and mounts the owl into this canvas. A failed chunk load,
 * a renderer that cannot start, or a context lost later all end in onFail.
 */
function Owl3DCanvas({ state, onReady, onFail }: CanvasProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const owlRef = useRef<OwlController | null>(null);
    const stateRef = useRef(state);

    useEffect(() => {
        let cancelled = false;
        import('./Owl3D')
            .then(({ mountOwl }) => {
                if (cancelled || !canvasRef.current) return;
                owlRef.current = mountOwl(canvasRef.current, { state: stateRef.current, onLost: onFail });
                onReady();
            })
            .catch(() => {
                if (!cancelled) onFail();
            });
        return () => {
            cancelled = true;
            owlRef.current?.destroy();
            owlRef.current = null;
        };
    }, [onReady, onFail]);

    useEffect(() => {
        stateRef.current = state;
        owlRef.current?.setState(state);
    }, [state]);

    return <canvas ref={canvasRef} className="block size-full" />;
}

/**
 * The greeting owl: the 3D placeholder owl where it can run, otherwise the SVG eyes with the same state.
 * Where 3D can run, both sit in one fixed-size stage, so loading, a lost context, or the off switch
 * never moves the layout.
 */
export function OwlHero({ state }: { state: EyeState }) {
    const t = useT();
    const reducedMotion = useReducedMotion();
    const released = useReleasedWhileHidden();
    const [webgl] = useState(canUseWebGL);
    const [enabled, setEnabled] = useState(readSwitch);
    const [failed, setFailed] = useState(false);
    const [ready, setReady] = useState(false);

    const offerSwitch = webgl && !reducedMotion;
    const show3D = offerSwitch && enabled && !failed;
    const mount3D = show3D && !released;
    const visible3D = mount3D && ready;

    useEffect(() => {
        if (!mount3D) setReady(false);
    }, [mount3D]);

    const markReady = useCallback(() => setReady(true), []);
    const markFailed = useCallback(() => setFailed(true), []);

    const toggle = () => {
        const next = !show3D;
        writeSwitch(next);
        setEnabled(next);
        setFailed(false);
    };

    // Nothing will ever swap in, so the eyes keep their own size, as before the 3D owl existed.
    if (!offerSwitch) return <OwlEyes state={state} size={180} look={0} />;

    return (
        <div className="flex flex-col items-center gap-1 md:items-start">
            <div className="relative h-[200px] w-[240px]" aria-hidden="true">
                <div className={`absolute inset-0 flex items-center justify-center transition-opacity duration-300 ${visible3D ? 'opacity-0' : 'opacity-100'}`}>
                    <OwlEyes state={state} size={180} look={0} />
                </div>
                {mount3D && (
                    <div className={`absolute inset-0 transition-opacity duration-300 ${visible3D ? 'opacity-100' : 'opacity-0'}`}>
                        <Owl3DCanvas state={state} onReady={markReady} onFail={markFailed} />
                    </div>
                )}
            </div>
            <button type="button" className="btn btn-quiet text-sm" onClick={toggle}>
                {show3D ? t('auth.owl.turn_off') : t('auth.owl.turn_on')}
            </button>
        </div>
    );
}
