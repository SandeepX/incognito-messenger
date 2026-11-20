<div
    x-data="callOverlay"
    x-cloak
    x-show="$store.call.status !== 'idle'"
>
    <div
        class="call-overlay"
        x-show="fullScreen()"
        x-transition.opacity.duration.150ms
        role="dialog"
        aria-modal="true"
        :aria-label="statusLabel()"
    >
        <div class="call-card">
            <div class="call-avatar-wrap">
                <span class="call-pulse" x-show="$store.call.status === 'incoming'"></span>
                <span class="call-pulse delay" x-show="$store.call.status === 'incoming'"></span>
                <img
                    class="call-avatar"
                    :src="avatarSrc()"
                    alt=""
                    x-on:error="$event.target.src = avatarFallback()"
                >
            </div>

            <h2 class="call-peer-name" x-text="$store.call.peer?.name || 'Unknown caller'"></h2>
            <div class="call-peer-handle" x-show="$store.call.peer?.username" x-text="'@' + ($store.call.peer?.username || '')"></div>

            <p class="call-status" aria-live="polite" x-text="statusLabel()"></p>

            <template x-if="$store.call.status === 'ended' && $store.call.error">
                <div class="call-error" role="alert" x-text="$store.call.error"></div>
            </template>

            <div class="call-timer" x-show="$store.call.status === 'active'" x-text="elapsedLabel()"></div>

            <div class="call-actions" x-show="$store.call.status === 'incoming'">
                <div class="call-btn-group">
                    <button class="call-btn decline" type="button" @click="decline()" aria-label="Decline audio call" title="Decline">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14c4-5 12-5 16 0l-3 3-3-2v-3c-1.5-.5-2.5-.5-4 0v3l-3 2z"/></svg>
                    </button>
                    <span class="call-btn-label">Decline</span>
                </div>
                <div class="call-btn-group">
                    <button
                        class="call-btn accept"
                        type="button"
                        x-ref="accept"
                        @click="accept()"
                        aria-label="Accept audio call"
                        title="Accept"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>
                    </button>
                    <span class="call-btn-label">Accept</span>
                </div>
            </div>

            <div class="call-actions" x-show="['calling', 'ringing', 'connecting'].includes($store.call.status)">
                <div class="call-btn-group">
                    <button
                        class="call-btn end"
                        type="button"
                        @click="$store.call.status === 'connecting' ? end() : cancel()"
                        aria-label="End audio call"
                        title="End call"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14c4-5 12-5 16 0l-3 3-3-2v-3c-1.5-.5-2.5-.5-4 0v3l-3 2z"/></svg>
                    </button>
                    <span class="call-btn-label">End</span>
                </div>
            </div>

            <div class="call-retry" x-show="$store.call.status === 'ended'">
                <button class="call-link-btn" type="button" @click="retryCall()" x-show="$store.call.peer?.id">Try again</button>
                <button class="call-link-btn" type="button" @click="close()">Close</button>
            </div>
        </div>
    </div>

    <div
        class="call-mini"
        x-show="['active', 'reconnecting'].includes($store.call.status)"
        x-transition.opacity.duration.150ms
    >
        <span class="call-sr-only" aria-live="polite"
              x-text="$store.call.status === 'reconnecting' ? 'Reconnecting' : ($store.call.status === 'active' ? 'Call connected' : '')"></span>
        <img
            class="call-avatar sm"
            :src="avatarSrc()"
            alt=""
            x-on:error="$event.target.src = avatarFallback()"
        >
        <div class="call-mini-info">
            <span class="call-mini-name" x-text="$store.call.peer?.name || 'Audio call'"></span>
            <span class="call-mini-meta">
                <span
                    class="call-quality"
                    :class="$store.call.quality || 'unknown'"
                    :title="qualityLabel()"
                    :aria-label="qualityLabel()"
                ></span>
                <span class="call-mini-timer" x-text="$store.call.status === 'reconnecting' ? 'Reconnecting…' : elapsedLabel()"></span>
            </span>
        </div>

        <div class="call-mini-actions">
            <button
                class="call-mini-btn"
                type="button"
                x-show="$store.call.status === 'reconnecting'"
                @click="retry()"
                aria-label="Retry connection"
                title="Retry connection"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
            </button>

            <select
                class="call-select"
                x-show="outputSupported() && outputs.length > 1"
                x-model="speakerId"
                @change="selectOutput()"
                aria-label="Speaker output"
                title="Speaker output"
            >
                <option value="">System default</option>
                <template x-for="device in outputs" :key="device.id">
                    <option :value="device.id" x-text="device.label"></option>
                </template>
            </select>

            <button
                class="call-mini-btn"
                :class="{ muted: $store.call.isMuted }"
                type="button"
                @click="$store.call.toggleMute()"
                :aria-pressed="$store.call.isMuted"
                :aria-label="$store.call.isMuted ? 'Unmute microphone' : 'Mute microphone'"
                :title="$store.call.isMuted ? 'Unmute' : 'Mute'"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" x-show="!$store.call.isMuted"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg>
                <svg viewBox="0 0 24 24" aria-hidden="true" x-show="$store.call.isMuted"><path d="M9 9v3a3 3 0 0 0 5.1 2.1M15 9.3V6a3 3 0 0 0-5.8-1M5 5l14 14M19 10v2a7 7 0 0 1-10.6 6.1M12 19v3"/></svg>
            </button>

            <button
                class="call-mini-btn end"
                type="button"
                @click="end()"
                aria-label="End audio call"
                title="End call"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14c4-5 12-5 16 0l-3 3-3-2v-3c-1.5-.5-2.5-.5-4 0v3l-3 2z"/></svg>
            </button>
        </div>
    </div>
</div>

<div
    x-data="callDiagnostics"
    x-cloak
    x-show="enabled"
    class="call-diagnostics"
>
    <button
        type="button"
        class="call-diagnostics-toggle"
        @click="open = !open"
        :aria-expanded="open"
        aria-label="Connection diagnostics"
    >
        Connection diagnostics
    </button>
    <div x-show="open" class="call-diagnostics-body">
        <dl>
            <dt>Secure context</dt>
            <dd x-text="data.secureContext ? 'yes' : 'no'"></dd>
            <dt>Microphone</dt>
            <dd x-text="data.micPermission || 'unknown'"></dd>
            <dt>Echo / Reverb</dt>
            <dd x-text="data.echoState || 'unknown'"></dd>
            <dt>WebSocket URL</dt>
            <dd class="call-diagnostics-url" x-text="echoUrl()"></dd>
            <dt>ICE connection</dt>
            <dd x-text="data.iceState || 'none'"></dd>
            <dt>Peer connection</dt>
            <dd x-text="data.peerState || 'none'"></dd>
            <dt>Candidate type</dt>
            <dd x-text="data.candidateType || '—'"></dd>
        </dl>
    </div>
</div>
