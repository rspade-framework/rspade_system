/**
 * RSpade IDE Bridge - connectivity and grant lifecycle
 *
 * ONE object owns whether the bridge is reachable and which grant to present. Every
 * server-dependent provider asks it rather than deciding for itself, so "the container
 * is down" is answered once instead of by each feature discovering it separately
 * through its own failed request.
 *
 * WHAT IT DOES
 *
 *   on start        read the newest grant, then ping. Up to CONNECT_ATTEMPTS tries,
 *                   RETRY_DELAY_MS apart.
 *   every 15 min    re-read the newest grant from disk and ping again. The server
 *                   rotates on the same interval and keeps the previous grant valid,
 *                   so a refresh that lands anywhere inside the window is in time.
 *   on failure      go OFFLINE and poll every RECONNECT_POLL_MS until it comes back.
 *
 * WHY OFFLINE IS A STATE AND NOT AN ERROR PER REQUEST. Without it, every provider
 * retries a dead server on every keystroke, each paying the full connect timeout, and
 * the editor stutters for as long as the container is down. Holding the state means a
 * doomed request is refused instantly and the recovery is one poll, not thousands.
 *
 * WHAT GOES OFFLINE. Only the features that need the server - formatting, definition
 * lookups, git decoration, refactors. Highlighting, completion, convention diagnostics
 * and folder colors are computed locally in a project that has already been IDENTIFIED
 * as RSpade, and a stopped container is no reason to take them away.
 *
 * NOT AN RSPADE PROJECT is a separate, terminal condition: no root means nothing here
 * ever starts, no timer is armed and no request is attempted. The extension is inert in
 * somebody else's repository rather than politely failing in it.
 */

import * as vscode from 'vscode';
import * as http from 'http';
import * as https from 'https';
import { find_rspade_root, resolveServerUrl, readIdeToken } from './ide_bridge_config';

/** How many times a connection attempt pings before declaring the bridge down. */
const CONNECT_ATTEMPTS = 3;

/** Delay between those attempts. */
const RETRY_DELAY_MS = 10_000;

/**
 * Grant refresh interval. Matches the server's rotation schedule
 * (Ide_Bridge_Service::rotate_grants, every 15 minutes). The two do not need to be in
 * step: the server keeps the PREVIOUS grant valid precisely so a client refreshing on
 * its own clock is never caught holding a retired one.
 */
const REFRESH_MS = 15 * 60 * 1000;

/** How often to look for the server again once it is known to be down. */
const RECONNECT_POLL_MS = 2 * 60 * 1000;

/** The cheapest endpoint that proves both reachability and a valid grant. */
const PING_ENDPOINT = '/_ide/service/health';

/** Per-ping socket budget. A ping that has not answered in this long has answered. */
const PING_TIMEOUT_MS = 5_000;

export class Bridge_Connectivity {
    private output_channel: vscode.OutputChannel;
    private status_bar: vscode.StatusBarItem;

    private rspade_root: string | undefined;
    private online = false;
    private server_url: string | null = null;
    private token: string | null = null;

    private refresh_timer: NodeJS.Timeout | undefined;
    private reconnect_timer: NodeJS.Timeout | undefined;
    private stopped = false;

    /** Set while a connect sequence is in flight, so timers cannot stack attempts. */
    private connecting = false;

    private readonly on_change_emitter = new vscode.EventEmitter<boolean>();

    /** Fires whenever the bridge goes up or down. */
    public readonly onDidChangeConnectivity = this.on_change_emitter.event;

    constructor(output_channel: vscode.OutputChannel) {
        this.output_channel = output_channel;
        this.status_bar = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
        this.status_bar.command = 'rspade.reconnectBridge';
    }

    /**
     * Begin managing connectivity. Returns immediately; the first connect runs in the
     * background so activation is never blocked on a server that may not be there.
     */
    public start(): void {
        this.rspade_root = find_rspade_root();

        if (!this.rspade_root) {
            // Not an RSpade project. Nothing to connect to, nothing to poll for.
            this.log('No RSpade project root - the bridge is not started.');
            return;
        }

        void this.attempt_connection('startup');
    }

    /** True when server-dependent features may run. */
    public is_online(): boolean {
        return this.online;
    }

    /** The URL to call, or null while offline. */
    public current_server_url(): string | null {
        return this.online ? this.server_url : null;
    }

    /** The grant secret to present, or null while offline. */
    public current_token(): string | null {
        return this.online ? this.token : null;
    }

    /**
     * Re-read the grant from disk out of band.
     *
     * The refresh timer is the normal path; this is for a request that was rejected as
     * unauthenticated. A machine that slept through several rotations wakes holding a
     * grant the server has retired, and its next refresh may be minutes away - reading
     * again immediately turns that into one retried request instead of an outage.
     *
     * @returns the newly read token, or null if none could be read.
     */
    public refresh_token_now(): string | null {
        if (!this.rspade_root) {
            return null;
        }

        try {
            this.token = readIdeToken(this.rspade_root);
            this.log('Grant re-read on demand.');
            return this.token;
        } catch (e) {
            this.log(`Could not re-read the grant: ${e instanceof Error ? e.message : String(e)}`);
            return null;
        }
    }

    /** Force a connection attempt now (the status bar item's command). */
    public reconnect_now(): void {
        if (!this.rspade_root) {
            vscode.window.showInformationMessage('RSpade: this workspace is not an RSpade project.');
            return;
        }
        void this.attempt_connection('manual');
    }

    public dispose(): void {
        this.stopped = true;
        this.clear_timers();
        this.status_bar.dispose();
        this.on_change_emitter.dispose();
    }

    // =================================================================
    // Internals
    // =================================================================

    /**
     * Read credentials, then ping up to CONNECT_ATTEMPTS times.
     *
     * A missing grant is NOT retried within the sequence: the file either exists or it
     * does not, and re-reading it three times ten seconds apart proves nothing. It
     * drops straight to the reconnect poll, which is what eventually notices the site
     * being loaded in a browser for the first time.
     */
    private async attempt_connection(reason: string): Promise<void> {
        if (this.stopped || this.connecting || !this.rspade_root) {
            return;
        }

        this.connecting = true;
        this.clear_timers();

        try {
            this.log(`Connecting (${reason})...`);

            try {
                this.server_url = resolveServerUrl(this.rspade_root);
                this.token = readIdeToken(this.rspade_root);
            } catch (e) {
                this.go_offline(e instanceof Error ? e.message : String(e));
                return;
            }

            for (let attempt = 1; attempt <= CONNECT_ATTEMPTS; attempt++) {
                if (this.stopped) {
                    return;
                }

                const reachable = await this.ping();
                if (reachable) {
                    this.go_online();
                    return;
                }

                if (attempt < CONNECT_ATTEMPTS) {
                    this.log(`Ping ${attempt}/${CONNECT_ATTEMPTS} failed; retrying in ${RETRY_DELAY_MS / 1000}s.`);
                    await sleep(RETRY_DELAY_MS);
                }
            }

            this.go_offline(`no response after ${CONNECT_ATTEMPTS} attempts`);
        } finally {
            this.connecting = false;
        }
    }

    /**
     * The scheduled refresh: pick up the newest grant, and confirm the server is still
     * answering with it. Re-reading without pinging would leave a dead bridge looking
     * healthy until some provider tripped over it.
     */
    private async refresh(): Promise<void> {
        if (this.stopped || !this.rspade_root) {
            return;
        }

        try {
            this.token = readIdeToken(this.rspade_root);
            this.server_url = resolveServerUrl(this.rspade_root);
        } catch (e) {
            this.go_offline(e instanceof Error ? e.message : String(e));
            return;
        }

        if (await this.ping()) {
            this.log('Grant refreshed; bridge still reachable.');
            this.arm_refresh();
            return;
        }

        // One bad ping is not a verdict - fall into the full retry sequence.
        void this.attempt_connection('refresh ping failed');
    }

    /** GET the health endpoint with the current credentials. Never throws. */
    private ping(): Promise<boolean> {
        return new Promise(resolve => {
            if (!this.server_url || !this.token) {
                resolve(false);
                return;
            }

            let url: URL;
            try {
                url = new URL(this.server_url + PING_ENDPOINT);
            } catch (e) {
                resolve(false);
                return;
            }

            const transport = url.protocol === 'https:' ? https : http;
            const request = transport.request(
                {
                    hostname: url.hostname,
                    port: url.port || (url.protocol === 'https:' ? 443 : 80),
                    path: url.pathname,
                    method: 'GET',
                    headers: { 'X-Ide-Token': this.token },
                    timeout: PING_TIMEOUT_MS,
                    // A development box commonly has a self-signed certificate; the
                    // grant is what authenticates, not the certificate chain.
                    rejectUnauthorized: false,
                } as any,
                response => {
                    response.resume();
                    resolve(response.statusCode === 200);
                }
            );

            request.on('timeout', () => request.destroy());
            request.on('error', () => resolve(false));
            request.end();
        });
    }

    private go_online(): void {
        const was_online = this.online;
        this.online = true;

        this.status_bar.text = '$(check) RSpade';
        this.status_bar.tooltip = `RSpade IDE bridge connected: ${this.server_url}`;
        this.status_bar.hide();

        if (!was_online) {
            this.log(`Bridge ONLINE (${this.server_url}).`);
            this.on_change_emitter.fire(true);
        }

        this.arm_refresh();
    }

    private go_offline(detail: string): void {
        const was_online = this.online;
        this.online = false;

        this.status_bar.text = '$(debug-disconnect) RSpade offline';
        this.status_bar.tooltip =
            `RSpade IDE bridge unreachable: ${detail}\n\n` +
            'Server-dependent features (formatting, go-to-definition, git decoration, ' +
            'refactors) are paused. Local features are unaffected. Click to retry.';
        this.status_bar.show();

        if (was_online) {
            this.log(`Bridge OFFLINE: ${detail}`);
            this.on_change_emitter.fire(false);
        } else {
            this.log(`Bridge still offline: ${detail}`);
        }

        this.arm_reconnect();
    }

    private arm_refresh(): void {
        this.clear_timers();
        if (!this.stopped) {
            this.refresh_timer = setTimeout(() => void this.refresh(), REFRESH_MS);
        }
    }

    private arm_reconnect(): void {
        this.clear_timers();
        if (!this.stopped) {
            this.reconnect_timer = setTimeout(() => void this.attempt_connection('poll'), RECONNECT_POLL_MS);
        }
    }

    private clear_timers(): void {
        if (this.refresh_timer) {
            clearTimeout(this.refresh_timer);
            this.refresh_timer = undefined;
        }
        if (this.reconnect_timer) {
            clearTimeout(this.reconnect_timer);
            this.reconnect_timer = undefined;
        }
    }

    private log(message: string): void {
        this.output_channel.appendLine(`[connectivity] ${new Date().toISOString()} ${message}`);
    }
}

function sleep(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * The process-wide instance. Providers import this rather than being handed one, so a
 * provider constructed anywhere still consults the same state.
 */
let instance: Bridge_Connectivity | undefined;

export function init_bridge_connectivity(output_channel: vscode.OutputChannel): Bridge_Connectivity {
    if (!instance) {
        instance = new Bridge_Connectivity(output_channel);
    }
    return instance;
}

/**
 * Whether server-dependent work may proceed.
 *
 * Defaults to TRUE when no manager has been initialised. That is deliberate: the only
 * way to reach that state is a code path that runs before activate(), and refusing
 * there would disable the bridge for a reason that has nothing to do with the server.
 */
export function bridge_is_online(): boolean {
    return instance ? instance.is_online() : true;
}

/** The managed grant, or null. */
export function bridge_token(): string | null {
    return instance ? instance.current_token() : null;
}

/** Re-read the grant after an unauthenticated response. */
export function bridge_refresh_token(): string | null {
    return instance ? instance.refresh_token_now() : null;
}
