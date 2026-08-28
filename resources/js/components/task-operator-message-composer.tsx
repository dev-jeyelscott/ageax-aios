import { Form, usePage } from '@inertiajs/react';
import { LoaderCircle, Mic, Send, Square, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { storeOperatorMessage } from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type VoiceState =
    'idle' | 'requesting' | 'recording' | 'transcribing' | 'ready' | 'error';

type RecorderStopDisposition = 'cancel' | 'error' | null;

type VoiceTranscriptionResponse = {
    transcript?: unknown;
    message?: unknown;
    failure_type?: unknown;
};

type ConvertedRecording = {
    blob: Blob;
    durationSeconds: number;
};

const MESSAGE_MAX_LENGTH = 4000;

const RECORDING_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/ogg;codecs=opus',
    'audio/mp4',
    'audio/webm',
] as const;

/**
 * Read Laravel's browser-readable XSRF token for the authenticated JSON request.
 */
function readXsrfToken(): string | null {
    const prefix = 'XSRF-TOKEN=';
    const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(prefix));

    if (!cookie) {
        return null;
    }

    try {
        return decodeURIComponent(cookie.slice(prefix.length));
    } catch {
        return null;
    }
}

/**
 * Select the first recording container explicitly supported by the current browser.
 */
function chooseRecorderMimeType(): string | undefined {
    if (typeof MediaRecorder === 'undefined') {
        return undefined;
    }

    return RECORDING_MIME_TYPES.find((mimeType) =>
        MediaRecorder.isTypeSupported(mimeType),
    );
}

/**
 * Stop every track belonging to one ephemeral microphone stream.
 */
function stopMediaStream(stream: MediaStream | null): void {
    stream?.getTracks().forEach((track) => {
        track.stop();
    });
}

/**
 * Write one ASCII string into a WAV DataView.
 */
function writeAscii(view: DataView, offset: number, value: string): void {
    for (let index = 0; index < value.length; index++) {
        view.setUint8(offset + index, value.charCodeAt(index));
    }
}

/**
 * Encode decoded browser audio as mono 16-bit PCM WAV for the existing trusted upload boundary.
 */
function encodeMonoPcm16Wav(audioBuffer: AudioBuffer): ArrayBuffer {
    const bytesPerSample = 2;
    const dataLength = audioBuffer.length * bytesPerSample;
    const wav = new ArrayBuffer(44 + dataLength);
    const view = new DataView(wav);

    writeAscii(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataLength, true);
    writeAscii(view, 8, 'WAVE');
    writeAscii(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, audioBuffer.sampleRate, true);
    view.setUint32(28, audioBuffer.sampleRate * bytesPerSample, true);
    view.setUint16(32, bytesPerSample, true);
    view.setUint16(34, 16, true);
    writeAscii(view, 36, 'data');
    view.setUint32(40, dataLength, true);

    const channels = Array.from(
        {
            length: audioBuffer.numberOfChannels,
        },
        (_, index) => audioBuffer.getChannelData(index),
    );

    let outputOffset = 44;

    for (let frame = 0; frame < audioBuffer.length; frame++) {
        let sample = 0;

        for (const channel of channels) {
            sample += channel[frame] ?? 0;
        }

        sample /= Math.max(channels.length, 1);

        const clamped = Math.max(-1, Math.min(1, sample));

        view.setInt16(
            outputOffset,
            clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff,
            true,
        );

        outputOffset += bytesPerSample;
    }

    return wav;
}

/**
 * Decode the browser-selected recorder format in memory and normalize it to WAV.
 */
async function convertRecordingToWav(
    recording: Blob,
): Promise<ConvertedRecording> {
    if (typeof AudioContext === 'undefined') {
        throw new Error(
            'This browser cannot prepare recorded audio for local transcription.',
        );
    }

    const context = new AudioContext();

    try {
        const decoded = await context.decodeAudioData(
            await recording.arrayBuffer(),
        );

        return {
            blob: new Blob([encodeMonoPcm16Wav(decoded)], {
                type: 'audio/wav',
            }),
            durationSeconds: decoded.duration,
        };
    } finally {
        if (context.state !== 'closed') {
            await context.close();
        }
    }
}

/**
 * Format elapsed recording seconds for the visible recording status.
 */
function formatRecordingDuration(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

/**
 * Format the configured byte limit for human-readable recovery guidance.
 */
function formatByteLimit(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
}

/**
 * Convert browser microphone failures into bounded and recoverable operator messages.
 */
function microphoneErrorMessage(error: unknown): string {
    if (error instanceof DOMException) {
        if (error.name === 'NotAllowedError') {
            return 'Microphone permission was denied. Allow microphone access in your browser settings, then try again.';
        }

        if (error.name === 'NotFoundError') {
            return 'No microphone was found. Connect or enable a microphone, then try again.';
        }

        if (error.name === 'NotReadableError' || error.name === 'AbortError') {
            return 'The microphone is busy or unavailable. Close other microphone users, then try again.';
        }

        if (error.name === 'SecurityError') {
            return 'Microphone access is blocked in this browser context. Use the normal keyboard input or reopen AIOS from a secure local context.';
        }
    }

    return 'Microphone capture could not start. You can still type normally and try the microphone again.';
}

/**
 * Read one bounded transcription response without assuming successful JSON.
 */
async function readTranscriptionResponse(
    response: Response,
): Promise<VoiceTranscriptionResponse> {
    try {
        const payload: unknown = await response.json();

        if (
            typeof payload === 'object' &&
            payload !== null &&
            !Array.isArray(payload)
        ) {
            return payload as VoiceTranscriptionResponse;
        }
    } catch {
        return {};
    }

    return {};
}

/**
 * Render the existing durable operator-message form with an ephemeral microphone input adapter.
 */
export default function TaskOperatorMessageComposer({
    projectId,
    taskId,
}: {
    projectId: number;
    taskId: number;
}) {
    const { voice } = usePage().props;

    const [body, setBody] = useState('');
    const [voiceState, setVoiceState] = useState<VoiceState>('idle');
    const [voiceMessage, setVoiceMessage] = useState<string | null>(null);
    const [recordingSeconds, setRecordingSeconds] = useState(0);

    const bodyRef = useRef('');
    const mountedRef = useRef(true);
    const recorderRef = useRef<MediaRecorder | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const recordedBytesRef = useRef(0);
    const recordingIntervalRef = useRef<number | null>(null);
    const recordingLimitRef = useRef<number | null>(null);
    const stopDispositionRef = useRef<RecorderStopDisposition>(null);
    const microphoneRequestSequenceRef = useRef(0);
    const transcriptionSequenceRef = useRef(0);
    const transcriptionAbortRef = useRef<AbortController | null>(null);

    const voiceAvailable =
        voice.enabled &&
        voice.max_audio_bytes > 0 &&
        voice.max_duration_seconds > 0;

    const voiceBusy =
        voiceState === 'requesting' ||
        voiceState === 'recording' ||
        voiceState === 'transcribing';

    /**
     * Clear every timer associated with the current recording attempt.
     */
    function clearRecordingTimers(): void {
        if (recordingIntervalRef.current !== null) {
            window.clearInterval(recordingIntervalRef.current);
            recordingIntervalRef.current = null;
        }

        if (recordingLimitRef.current !== null) {
            window.clearTimeout(recordingLimitRef.current);
            recordingLimitRef.current = null;
        }
    }

    /**
     * Release the active microphone immediately and forget the stream reference.
     */
    function releaseMicrophone(): void {
        const stream = streamRef.current;
        streamRef.current = null;

        stopMediaStream(stream);
    }

    /**
     * Surface one recoverable voice failure without changing the text submission path.
     */
    function setVoiceError(message: string): void {
        if (!mountedRef.current) {
            return;
        }

        setVoiceState('error');
        setVoiceMessage(message);
    }

    /**
     * Keep the durable form body under explicit operator control.
     */
    function handleBodyChange(event: ChangeEvent<HTMLTextAreaElement>): void {
        bodyRef.current = event.target.value;
        setBody(event.target.value);
    }

    /**
     * Clear only the confirmed text after the existing operator-message Action succeeds.
     */
    function handleFormSuccess(): void {
        bodyRef.current = '';
        setBody('');
        setVoiceState('idle');
        setVoiceMessage(null);
    }

    /**
     * Cancel a pending browser permission request and discard any stream returned later.
     */
    function cancelMicrophoneRequest(): void {
        microphoneRequestSequenceRef.current++;

        setVoiceState('idle');
        setVoiceMessage(
            'Microphone request cancelled. You can continue typing normally.',
        );
    }

    /**
     * Abort only the ephemeral transcription request and preserve the current editable text.
     */
    function cancelTranscription(): void {
        transcriptionSequenceRef.current++;

        transcriptionAbortRef.current?.abort();
        transcriptionAbortRef.current = null;

        setVoiceState('idle');
        setVoiceMessage(
            'Transcription cancelled. Your current text was not submitted.',
        );
    }

    /**
     * Convert and upload one completed recording to the existing authenticated transcription endpoint.
     */
    async function transcribeRecordedAudio(recordedBlob: Blob): Promise<void> {
        const sequence = ++transcriptionSequenceRef.current;
        const controller = new AbortController();

        transcriptionAbortRef.current = controller;

        setVoiceState('transcribing');
        setVoiceMessage('Preparing audio for local transcription.');

        try {
            const converted = await convertRecordingToWav(recordedBlob);

            if (
                !mountedRef.current ||
                sequence !== transcriptionSequenceRef.current
            ) {
                return;
            }

            if (converted.blob.size > voice.max_audio_bytes) {
                setVoiceError(
                    `The normalized recording exceeds the configured ${formatByteLimit(
                        voice.max_audio_bytes,
                    )} upload limit. Record a shorter sample and try again.`,
                );

                return;
            }

            if (converted.durationSeconds > voice.max_duration_seconds + 1) {
                setVoiceError(
                    `The recording exceeds the configured ${voice.max_duration_seconds}-second duration limit. Record a shorter sample and try again.`,
                );

                return;
            }

            const xsrfToken = readXsrfToken();

            if (!xsrfToken) {
                setVoiceError(
                    'The secure request token is unavailable. Reload AIOS and try again.',
                );

                return;
            }

            const formData = new FormData();

            formData.append('audio', converted.blob, 'voice.wav');

            setVoiceMessage(
                'Transcribing locally. No instruction will be sent automatically.',
            );

            const response = await fetch(voice.transcription_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken,
                },
                body: formData,
                signal: controller.signal,
            });

            const payload = await readTranscriptionResponse(response);

            if (
                !mountedRef.current ||
                sequence !== transcriptionSequenceRef.current
            ) {
                return;
            }

            if (!response.ok) {
                const responseMessage =
                    typeof payload.message === 'string' &&
                    payload.message.trim() !== ''
                        ? payload.message
                        : response.status === 401 || response.status === 419
                          ? 'Your authenticated session is unavailable. Reload AIOS and sign in again.'
                          : 'Local transcription failed. You can keep typing and try recording again.';

                setVoiceError(responseMessage);

                return;
            }

            const transcript =
                typeof payload.transcript === 'string'
                    ? payload.transcript.trim()
                    : '';

            if (transcript === '') {
                setVoiceError(
                    'No speech transcript was returned. Try recording again or type the instruction manually.',
                );

                return;
            }

            const currentBody = bodyRef.current.trimEnd();
            const combined =
                currentBody === ''
                    ? transcript
                    : `${currentBody}\n${transcript}`;

            if (combined.length > MESSAGE_MAX_LENGTH) {
                setVoiceError(
                    `The transcript would exceed the ${MESSAGE_MAX_LENGTH}-character instruction limit. Shorten the current text or record a shorter instruction.`,
                );

                return;
            }

            bodyRef.current = combined;
            setBody(combined);
            setVoiceState('ready');
            setVoiceMessage(
                'Transcript ready. Review or edit the text, then explicitly confirm before sending.',
            );
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            if (
                mountedRef.current &&
                sequence === transcriptionSequenceRef.current
            ) {
                setVoiceError(
                    error instanceof Error && error.message.trim() !== ''
                        ? error.message
                        : 'Recorded audio could not be prepared for local transcription. You can still type normally.',
                );
            }
        } finally {
            if (transcriptionAbortRef.current === controller) {
                transcriptionAbortRef.current = null;
            }
        }
    }

    /**
     * Finalize one MediaRecorder attempt and immediately discard its raw chunks after handoff.
     */
    async function handleRecorderStopped(
        recorder: MediaRecorder,
    ): Promise<void> {
        clearRecordingTimers();
        releaseMicrophone();

        if (recorderRef.current === recorder) {
            recorderRef.current = null;
        }

        const disposition = stopDispositionRef.current;
        stopDispositionRef.current = null;

        const chunks = chunksRef.current;
        chunksRef.current = [];
        recordedBytesRef.current = 0;

        if (disposition === 'cancel') {
            if (mountedRef.current) {
                setVoiceState('idle');
                setVoiceMessage(
                    'Recording cancelled. No audio was transcribed or submitted.',
                );
            }

            return;
        }

        if (disposition === 'error') {
            return;
        }

        if (chunks.length === 0) {
            setVoiceError(
                'The microphone recording did not contain audio data. Try again or type the instruction manually.',
            );

            return;
        }

        const recordedBlob = new Blob(chunks, {
            type:
                recorder.mimeType ||
                chunks[0]?.type ||
                'application/octet-stream',
        });

        await transcribeRecordedAudio(recordedBlob);
    }

    /**
     * Collect bounded recorder chunks while the microphone remains active.
     */
    function handleRecorderData(event: BlobEvent): void {
        if (event.data.size < 1) {
            return;
        }

        chunksRef.current.push(event.data);
        recordedBytesRef.current += event.data.size;

        const recorder = recorderRef.current;

        if (
            recordedBytesRef.current > voice.max_audio_bytes &&
            recorder?.state === 'recording'
        ) {
            setVoiceMessage(
                'Recording reached the configured upload boundary. Finishing the recording now.',
            );

            stopRecording(false);
        }
    }

    /**
     * Surface MediaRecorder runtime failure while ensuring the microphone is released.
     */
    function handleRecorderError(recorder: MediaRecorder): void {
        stopDispositionRef.current = 'error';

        clearRecordingTimers();

        if (recorder.state !== 'inactive') {
            try {
                recorder.stop();
            } catch {
                // The recorder may already be stopping after the browser error.
            }
        }

        releaseMicrophone();

        setVoiceError(
            'Microphone recording failed. The microphone was released and normal text input remains available.',
        );
    }

    /**
     * Stop the active recorder either for transcription or explicit cancellation.
     */
    function stopRecording(discard: boolean): void {
        const recorder = recorderRef.current;

        clearRecordingTimers();

        if (!recorder || recorder.state === 'inactive') {
            releaseMicrophone();

            if (discard) {
                chunksRef.current = [];
                recordedBytesRef.current = 0;
                setVoiceState('idle');
                setVoiceMessage('Recording cancelled. No audio was submitted.');
            }

            return;
        }

        stopDispositionRef.current = discard ? 'cancel' : null;

        if (discard) {
            setVoiceMessage('Cancelling recording.');
        }

        try {
            recorder.stop();
        } catch {
            stopDispositionRef.current = 'error';

            setVoiceError(
                'The browser could not finish the recording safely. The microphone was released.',
            );
        } finally {
            releaseMicrophone();
        }
    }

    /**
     * Request microphone access and start one bounded MediaRecorder attempt.
     */
    async function startRecording(): Promise<void> {
        if (!voiceAvailable) {
            setVoiceError(
                'Local microphone transcription is unavailable. You can still type the instruction normally.',
            );

            return;
        }

        if (
            typeof navigator === 'undefined' ||
            !navigator.mediaDevices?.getUserMedia ||
            typeof MediaRecorder === 'undefined'
        ) {
            setVoiceError(
                'This browser does not provide the required microphone recording APIs. Use the normal keyboard input instead.',
            );

            return;
        }

        const requestSequence = ++microphoneRequestSequenceRef.current;

        chunksRef.current = [];
        recordedBytesRef.current = 0;
        stopDispositionRef.current = null;
        setRecordingSeconds(0);
        setVoiceState('requesting');
        setVoiceMessage('Waiting for microphone permission.');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: true,
                },
                video: false,
            });

            if (
                !mountedRef.current ||
                requestSequence !== microphoneRequestSequenceRef.current
            ) {
                stopMediaStream(stream);

                return;
            }

            streamRef.current = stream;

            const mimeType = chooseRecorderMimeType();

            const recorder = mimeType
                ? new MediaRecorder(stream, {
                      mimeType,
                  })
                : new MediaRecorder(stream);

            recorderRef.current = recorder;

            recorder.ondataavailable = handleRecorderData;

            recorder.onerror = () => {
                handleRecorderError(recorder);
            };

            recorder.onstop = () => {
                void handleRecorderStopped(recorder);
            };

            recorder.start(1000);

            setVoiceState('recording');
            setVoiceMessage(
                'Recording. Stop when finished, or cancel to discard the sample.',
            );

            recordingIntervalRef.current = window.setInterval(() => {
                setRecordingSeconds((current) =>
                    Math.min(current + 1, voice.max_duration_seconds),
                );
            }, 1000);

            recordingLimitRef.current = window.setTimeout(() => {
                stopRecording(false);
            }, voice.max_duration_seconds * 1000);
        } catch (error) {
            if (
                requestSequence !== microphoneRequestSequenceRef.current ||
                !mountedRef.current
            ) {
                return;
            }

            releaseMicrophone();

            setVoiceError(microphoneErrorMessage(error));
        }
    }

    /**
     * Release every ephemeral browser audio resource when this composer unmounts.
     */
    useEffect(() => {
        return () => {
            mountedRef.current = false;

            transcriptionAbortRef.current?.abort();
            transcriptionAbortRef.current = null;

            if (recordingIntervalRef.current !== null) {
                window.clearInterval(recordingIntervalRef.current);
            }

            if (recordingLimitRef.current !== null) {
                window.clearTimeout(recordingLimitRef.current);
            }

            const recorder = recorderRef.current;

            if (recorder) {
                recorder.ondataavailable = null;
                recorder.onerror = null;
                recorder.onstop = null;

                if (recorder.state !== 'inactive') {
                    try {
                        recorder.stop();
                    } catch {
                        // Cleanup still releases the stream below.
                    }
                }
            }

            recorderRef.current = null;

            stopMediaStream(streamRef.current);
            streamRef.current = null;

            chunksRef.current = [];
            recordedBytesRef.current = 0;
        };
    }, []);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Send className="size-4 text-primary" />
                    Message an agent
                </CardTitle>
                <CardDescription>
                    Saved for the selected role&apos;s next fresh execution.
                    Voice only prepares editable text and never sends an
                    instruction automatically.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <Form
                    {...storeOperatorMessage.form({
                        project: projectId,
                        task: taskId,
                    })}
                    className="grid gap-3"
                    onSuccess={handleFormSuccess}
                >
                    {({ errors, processing }) => {
                        const describedBy = [
                            'operator-message-help',
                            errors.body ? 'operator-message-error' : null,
                            voiceMessage ? 'voice-transcription-status' : null,
                        ]
                            .filter(Boolean)
                            .join(' ');

                        return (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="recipient_role">
                                        Recipient
                                    </Label>

                                    <select
                                        id="recipient_role"
                                        name="recipient_role"
                                        defaultValue="coder"
                                        disabled={processing}
                                        className="h-9 rounded-md border border-input bg-surface-sunken px-3 text-sm text-foreground transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option value="coder">Coder</option>
                                        <option value="reviewer">
                                            Reviewer
                                        </option>
                                    </select>

                                    <InputError
                                        message={errors.recipient_role}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <Label htmlFor="body">
                                            Instruction
                                        </Label>

                                        <span className="font-mono text-2xs text-muted-foreground">
                                            {body.length}/{MESSAGE_MAX_LENGTH}
                                        </span>
                                    </div>

                                    <Textarea
                                        id="body"
                                        name="body"
                                        required
                                        maxLength={MESSAGE_MAX_LENGTH}
                                        rows={6}
                                        value={body}
                                        disabled={processing}
                                        onChange={handleBodyChange}
                                        aria-invalid={
                                            errors.body ? true : undefined
                                        }
                                        aria-describedby={
                                            describedBy || undefined
                                        }
                                        placeholder="Add context, a correction, or a question for the next agent run."
                                        className="min-h-32 resize-y bg-surface-sunken"
                                    />

                                    <InputError
                                        id="operator-message-error"
                                        message={errors.body}
                                    />
                                </div>

                                <div className="grid gap-2 rounded-lg border border-border-subtle bg-foreground/[0.025] p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {!voiceAvailable ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled
                                            >
                                                <Mic className="size-3.5" />
                                                Microphone unavailable
                                            </Button>
                                        ) : voiceState === 'requesting' ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled
                                                >
                                                    <LoaderCircle className="size-3.5 animate-spin" />
                                                    Requesting permission
                                                </Button>

                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={
                                                        cancelMicrophoneRequest
                                                    }
                                                >
                                                    <X className="size-3.5" />
                                                    Cancel
                                                </Button>
                                            </>
                                        ) : voiceState === 'recording' ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        stopRecording(false)
                                                    }
                                                >
                                                    <Square className="size-3.5" />
                                                    Stop &amp; transcribe
                                                </Button>

                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        stopRecording(true)
                                                    }
                                                >
                                                    <X className="size-3.5" />
                                                    Cancel recording
                                                </Button>

                                                <span
                                                    aria-live="polite"
                                                    className="font-mono text-2xs text-primary"
                                                >
                                                    REC{' '}
                                                    {formatRecordingDuration(
                                                        recordingSeconds,
                                                    )}{' '}
                                                    /{' '}
                                                    {formatRecordingDuration(
                                                        voice.max_duration_seconds,
                                                    )}
                                                </span>
                                            </>
                                        ) : voiceState === 'transcribing' ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled
                                                >
                                                    <LoaderCircle className="size-3.5 animate-spin" />
                                                    Transcribing
                                                </Button>

                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={
                                                        cancelTranscription
                                                    }
                                                >
                                                    <X className="size-3.5" />
                                                    Cancel transcription
                                                </Button>
                                            </>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                                onClick={() => {
                                                    void startRecording();
                                                }}
                                            >
                                                <Mic className="size-3.5" />
                                                {voiceState === 'ready'
                                                    ? 'Record again'
                                                    : 'Record with microphone'}
                                            </Button>
                                        )}
                                    </div>

                                    {!voiceAvailable && (
                                        <p className="text-2xs leading-5 text-muted-foreground">
                                            Local microphone transcription is
                                            disabled or unavailable. You can
                                            still type normally.
                                        </p>
                                    )}

                                    {voiceMessage && voiceState === 'error' ? (
                                        <Alert
                                            id="voice-transcription-status"
                                            variant="destructive"
                                            className="border-destructive/25 bg-destructive/10"
                                        >
                                            <AlertDescription>
                                                {voiceMessage}
                                            </AlertDescription>
                                        </Alert>
                                    ) : voiceMessage ? (
                                        <p
                                            id="voice-transcription-status"
                                            aria-live="polite"
                                            className={
                                                voiceState === 'ready'
                                                    ? 'text-xs leading-5 text-success-foreground'
                                                    : 'text-xs leading-5 text-muted-foreground'
                                            }
                                        >
                                            {voiceMessage}
                                        </p>
                                    ) : null}

                                    <p className="text-2xs leading-5 text-muted-foreground">
                                        Raw audio stays ephemeral. The browser
                                        keeps the sample in memory only long
                                        enough to prepare the existing secure
                                        local transcription upload.
                                    </p>
                                </div>

                                <p
                                    id="operator-message-help"
                                    className="text-2xs text-muted-foreground"
                                >
                                    Review all text before sending. Do not
                                    include credentials or secrets.
                                </p>

                                <Button
                                    type="submit"
                                    disabled={processing || voiceBusy}
                                    className="shadow-glow-sm"
                                >
                                    <Send className="size-4" />
                                    {processing
                                        ? 'Sending...'
                                        : voiceState === 'ready'
                                          ? 'Confirm transcript and send'
                                          : 'Send instruction'}
                                </Button>
                            </>
                        );
                    }}
                </Form>
            </CardContent>
        </Card>
    );
}
