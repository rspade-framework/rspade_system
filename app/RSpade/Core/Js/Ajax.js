// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Client-side Ajax: calls RSX #[Ajax_Endpoint] methods (await Controller.method(params)).
 *
 * ONE TRANSPORT, EVERY MODE. Every call is sent to /_ajax/_batch - a lone call as a batch
 * of one - and answered from its own key in the response. Calls made in the same
 * synchronous turn share one request (at most MAX_BATCH_SIZE calls each) when
 * config('rsx.ajax.batching') is on - the default in debug and production; development
 * defaults it off, so every call is its own request (its own network-panel entry). Nothing
 * else differs: the same endpoint answers the same way in every mode, batched or not.
 *
 * NO MERGING. Two identical calls are two calls: every Ajax call is a POST, and the
 * transport cannot know an endpoint is a read - merging two identical "add note" calls
 * would run it once and tell both callers it succeeded. Reads that are safe to share are
 * coalesced where they are known to be reads: the ORM fetch (Rsx_Js_Model) and jqhtml's
 * load coordinator (same component, same args, loading at the same time).
 *
 * EVERY CALL IS SETTLED. A call resolves with its endpoint's value or rejects with an
 * Error carrying .code (an Ajax.ERROR_* constant) and .metadata - including when the
 * request fails as a whole, and when an answer arrives without the call in it.
 */
class Ajax {
    // Error code constants (must match server-side Ajax::ERROR_* constants)
    static ERROR_VALIDATION = 'validation';
    static ERROR_NOT_FOUND = 'not_found';
    static ERROR_UNAUTHORIZED = 'unauthorized';
    static ERROR_AUTH_REQUIRED = 'auth_required';
    static ERROR_SESSION_EXPIRED = 'session_expired';   // The sign-in outlived its limits and was just ended
    static ERROR_FATAL = 'fatal';
    static ERROR_GENERIC = 'generic';
    static ERROR_QUESTION = 'question';             // Not a failure: a pending server-driven question
    static ERROR_SERVER = 'server_error';           // Client-generated (HTTP 5xx, or an answer missing a call)
    static ERROR_NETWORK = 'network_error';         // Client-generated (connection failed)
    static ERROR_PHP_EXCEPTION = 'php_exception';   // Client-generated (PHP exception with file/line/backtrace)

    // The most calls one request carries. The server refuses a batch over
    // rsx.ajax.batch_max_calls (100), so this only splits what a page sends at once.
    static MAX_BATCH_SIZE = 20;

    /**
     * Initialize Ajax system
     * Called automatically when class is loaded
     */
    static _on_framework_core_init() {
        // Calls made this turn, not yet sent: [{call_id, controller, action, params, hydrate, resolve, reject}]
        Ajax._queue = [];
        Ajax._flush_scheduled = false;
        Ajax._call_counter = 0;

        // An Ajax failure nobody caught gets the framework's error dialog instead of the
        // browser's bare "Uncaught (in promise)". Recognised by the marker every Ajax error
        // carries (Ajax._error()), so it holds whatever promise chain the error travelled -
        // a generated stub, the ORM fetch, an app's own async wrapper.
        window.addEventListener('unhandledrejection', async (event) => {
            const error = event.reason;

            if (!error || error.__rsx_ajax_error !== true) {
                return;
            }

            event.preventDefault();
            console.error('Uncaught Ajax error:', error);

            // Suppress errors during navigation grace period
            // After page navigation, pending requests from the old page may error out
            // These errors are not relevant to the new page
            if (typeof Spa !== 'undefined' && Spa.is_within_navigation_grace_period()) {
                console_debug('AJAX', 'Suppressing error modal during navigation grace period');
                return;
            }

            // Show error modal for uncaught Ajax errors
            // In debug mode, use fatal_error() for detailed file/line info
            if (typeof Modal !== 'undefined') {
                if (window.rsxapp?.debug && Modal.fatal_error) {
                    await Modal.fatal_error(error, 'Uncaught Ajax Error');
                } else if (Modal.error) {
                    await Modal.error(error, 'Uncaught Ajax Error');
                }
            }
        });
    }

    /**
     * Make an AJAX call to an RSX controller action
     *
     * @param {string|object|function} url - The Ajax URL (e.g., '/_ajax/Controller_Name/action_name') or an object/function with a .path property
     * @param {object} params - Parameters to send to the action
     * @returns {Promise} - Resolves with the return value, rejects with error
     */
    static call(url, params = {}) {
        return Ajax._enqueue(url, params, true);
    }

    /**
     * Ajax.call() without model hydration: resolves with the endpoint's value exactly as
     * decoded from JSON. For the ORM fetch, which hydrates a SEPARATE instance for each of
     * the callers sharing one lookup.
     *
     * @param {string} url
     * @param {object} params
     * @returns {Promise<*>}
     */
    static _call_unhydrated(url, params = {}) {
        return Ajax._enqueue(url, params, false);
    }

    /**
     * Queue one call for this turn's request and schedule the flush.
     * @private
     */
    static _enqueue(url, params, hydrate) {
        return new Promise((resolve, reject) => {
            // URL errors reach the caller as a rejection, never a synchronous throw - every
            // caller holds a promise, in every case.
            let controller;
            let action;

            try {
                ({ controller, action } = Ajax.ajax_url_to_controller_action(url));
            } catch (error) {
                reject(Ajax._error(Ajax.ERROR_GENERIC, error.message));
                return;
            }

            console_debug('AJAX', `${controller}.${action}`, params);

            Ajax._queue.push({
                call_id: Ajax._call_counter++,
                controller: controller,
                action: action,
                params: params,
                hydrate: hydrate,
                resolve: resolve,
                reject: reject,
            });

            Ajax._schedule_flush();
        });
    }

    /**
     * Ensure exactly one flush is scheduled.
     *
     * setTimeout(0) is the flush SCHEDULER, not a deadline (owner ruling 2026-08-13): it
     * defers the request until every synchronously-running piece of code - a template
     * rendering twenty rows, a component's on_load, a Promise.all - has queued its calls,
     * so they leave together. NOT requestAnimationFrame: rAF does not fire in a hidden tab.
     * @private
     */
    static _schedule_flush() {
        if (Ajax._flush_scheduled) {
            return;
        }

        Ajax._flush_scheduled = true;
        setTimeout(() => Ajax._flush(), 0);
    }

    /**
     * Send everything queued: chunks of MAX_BATCH_SIZE calls, or one call per request when
     * config('rsx.ajax.batching') is off.
     * @private
     */
    static _flush() {
        // Cleared FIRST: a call queued while these requests are in flight belongs to the
        // NEXT flush and must be able to schedule it.
        Ajax._flush_scheduled = false;

        const queued = Ajax._queue;
        Ajax._queue = [];

        const chunk_size = window.rsxapp.ajax_batching ? Ajax.MAX_BATCH_SIZE : 1;

        for (let offset = 0; offset < queued.length; offset += chunk_size) {
            Ajax._send(queued.slice(offset, offset + chunk_size));
        }
    }

    /**
     * One request to /_ajax/_batch, then each call settled from its own key.
     * @private
     */
    static async _send(calls) {
        // Rsx_Portal.internal_url() rebases the path onto the channel this page is served
        // under, so a portal page's calls are dispatched as portal requests.
        const url = Rsx_Portal.internal_url('/_ajax/_batch');

        let response;

        try {
            response = await $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    batch_calls: calls.map((call) => ({
                        call_id: call.call_id,
                        controller: call.controller,
                        action: call.action,
                        params: call.params,
                    })),
                }),
                dataType: 'json',
                __local_integration: true, // Bypass $.ajax override
            });
        } catch (xhr) {
            const error = Ajax._http_failure(xhr, url);

            for (const call of calls) {
                call.reject(error);
            }

            return;
        }

        // Every call is settled from its own key; an answer without it is a protocol fault,
        // never a promise left pending.
        for (const call of calls) {
            const endpoint = `/_ajax/${call.controller}/${call.action}`;
            const envelope = response ? response['C_' + call.call_id] : undefined;

            if (envelope === undefined || envelope === null || typeof envelope !== 'object') {
                Debugger.log_error({
                    message: `Ajax answer missing call ${call.controller}.${call.action}`,
                    type: 'ajax_protocol',
                    endpoint: endpoint,
                });
                call.reject(Ajax._error(Ajax.ERROR_SERVER, 'The server did not answer this request. Please try again.'));
                continue;
            }

            try {
                Ajax._process_side_channels(envelope);

                if (envelope._success === true) {
                    // @JS-AJAX-02-EXCEPTION - the transport unwraps _ajax_return_value
                    const value = envelope._ajax_return_value;
                    call.resolve(call.hydrate ? Rsx_Js_Model._instantiate_models_recursive(value) : value);
                } else {
                    call.reject(Ajax._envelope_error(envelope, endpoint));
                }
            } catch (error) {
                // Hydration or a side channel threw for THIS call: it is this call's failure.
                call.reject(error);
            }
        }
    }

    /**
     * Upload a multipart FormData to the framework upload endpoint.
     *
     * The one client-side upload transport. It owns the two things every caller was
     * getting wrong on its own:
     *
     *   - the CHANNEL: Rsx_Portal.internal_url() rebases /_upload onto the base this
     *     page is served under, so a portal upload is dispatched as a portal request.
     *   - the CSRF TOKEN: multipart uploads must use native fetch (jQuery cannot send
     *     FormData through the $.ajax chokepoint that normally attaches the header),
     *     so the header is attached here instead. A session-bearing POST without it
     *     is rejected by Rsx_Csrf.
     *
     * It also refuses an over-size file BEFORE sending it. The server is the enforcement
     * boundary and always will be - this check exists so the user is told in the first
     * second rather than after minutes of pushing a doomed file up a slow connection.
     * The ceiling is window.rsxapp.files.max_file_size, injected from the
     * rsx.files.max_file_size config on every page.
     *
     * Resolves with the parsed endpoint response ({success, attachment}); throws with
     * the endpoint's own error text on any failure.
     *
     * @param {FormData} form_data Must carry the 'file' part
     * @returns {Promise<object>} The parsed upload response
     */
    /**
     * The upload size ceiling as a string you can put in front of a user: "100 MB".
     *
     * Every "Max size 25MB" in a template is a number that will be wrong the day the
     * limit changes, and nobody will notice because nothing breaks - the label just
     * quietly lies. This reads window.rsxapp.files.max_file_size, so the notice and the
     * enforcement can never disagree.
     *
     * Rounding is chosen for a LABEL, not for accuracy: whole MB up to 1 GB, one decimal
     * from 1 GB to 10 GB (so 1.2 GB does not read as "1 GB"), whole GB above that. Below
     * 1 MB it falls back to whole KB rather than rounding to a meaningless "0 MB".
     *
     * PHP mirror with identical output: Ajax::max_file_size_human().
     *
     * @returns {string} e.g. "100 MB", "1.2 GB", "12 GB", or "Unlimited" when uncapped
     */
    static max_file_size_human() {
        return Ajax.bytes_to_size_label(window.rsxapp?.files?.max_file_size || 0);
    }

    /**
     * The label formatter behind max_file_size_human(), exposed for an app that caps
     * tighter than the framework and wants its own number rendered the same way.
     *
     * @param {number} bytes 0 (or anything non-positive) means no ceiling
     * @returns {string}
     */
    static bytes_to_size_label(bytes) {
        const value = int(bytes);

        if (value <= 0) {
            return 'Unlimited';
        }

        const KB = 1024;
        const MB = KB * 1024;
        const GB = MB * 1024;

        if (value < MB) {
            return Math.round(value / KB) + ' KB';
        }
        if (value < GB) {
            return Math.round(value / MB) + ' MB';
        }
        if (value < 10 * GB) {
            // One decimal, trailing .0 dropped: "1.2 GB", but "2 GB" not "2.0 GB".
            return Number((value / GB).toFixed(1)) + ' GB';
        }

        return Math.round(value / GB) + ' GB';
    }

    static async upload(form_data) {
        // 0 (or an absent block on a page rendered before this shipped) means no framework
        // ceiling - which must read as UNLIMITED, never as "reject everything".
        const max_file_size = window.rsxapp?.files?.max_file_size || 0;
        const file = form_data.get('file');

        if (max_file_size > 0 && file && is_numeric(file.size) && file.size > max_file_size) {
            throw new Error(
                'File is too large: ' + bytes_to_human(file.size)
                + ' exceeds the ' + bytes_to_human(max_file_size) + ' limit.'
            );
        }

        const headers = {};
        if (window.rsxapp && window.rsxapp.csrf) {
            headers['X-CSRF-Token'] = window.rsxapp.csrf;
        }

        const response = await fetch(Rsx_Portal.internal_url('/_upload'), {
            method: 'POST',
            body: form_data,
            headers: headers,
        });

        // A refusal in front of PHP (the web server's 413 for an oversized body, a 502)
        // answers HTML, not JSON: report its status rather than a JSON parse error.
        let result;
        try {
            result = await response.json();
        } catch (e) {
            throw new Error(`Upload failed (HTTP ${response.status} ${response.statusText})`);
        }

        if (!response.ok || !result.success || !result.attachment) {
            throw new Error(result.error || 'Upload failed');
        }

        return result;
    }

    /**
     * The Error for a request that failed as a whole (no JSON answer): one reading for
     * every call the request carried.
     * @private
     */
    static _http_failure(xhr, url) {
        let error;

        if (xhr.status >= 500) {
            error = Ajax._error(Ajax.ERROR_SERVER, 'A server error occurred. Please try again.');
            Debugger.log_error({
                message: `Ajax Server Error ${xhr.status}`,
                type: 'ajax_server_error',
                endpoint: url,
                status: xhr.status,
            });
        } else if (xhr.status === 0) {
            error = Ajax._error(Ajax.ERROR_NETWORK, 'Could not connect to server. Please check your connection.');
        } else {
            error = Ajax._error(Ajax.ERROR_GENERIC, Ajax._extract_error_message(xhr));
        }

        error.metadata = { status: xhr.status, statusText: xhr.statusText || '' };

        return error;
    }

    /**
     * An Error carrying an Ajax code, marked as an Ajax failure for the uncaught-error dialog.
     * @private
     */
    static _error(code, message, metadata = {}) {
        const error = new Error(message);
        error.code = code;
        error.metadata = metadata;
        error.__rsx_ajax_error = true;
        return error;
    }

    /**
     * The side channels every envelope may carry, direct or batched: console_debug
     * messages, the server clock and the user's zone, and pending flash alerts.
     * @private
     */
    static _process_side_channels(envelope) {
        if (envelope.console_debug && Array.isArray(envelope.console_debug)) {
            envelope.console_debug.forEach((msg) => {
                if (!Array.isArray(msg) || msg.length !== 2) {
                    throw new Error('Invalid console_debug message format - expected [channel, [arguments]]');
                }
                const [channel, args] = msg;
                console.log(channel, ...args);
            });
        }

        // Sync time with server (only on first AJAX or timezone change)
        if (envelope._server_time || envelope._user_timezone) {
            Rsx_Time.sync_from_ajax({
                server_time: envelope._server_time,
                user_timezone: envelope._user_timezone
            });
        }

        // The call signed somebody in, which moved the browser to a new session: every
        // later request from this page must carry the new session's CSRF token.
        if (envelope._csrf) {
            window.rsxapp.csrf = envelope._csrf;
        }

        if (envelope.flash_alerts && Array.isArray(envelope.flash_alerts)) {
            Server_Side_Flash.process(envelope.flash_alerts);
        }
    }

    /**
     * The Error a failed envelope rejects with - one reading for both transports, since
     * the server sends the same envelope for the same failure either way.
     * @private
     */
    static _envelope_error(envelope, endpoint) {
        const error_code = envelope.error_code || Ajax.ERROR_GENERIC;
        const error = Ajax._error(error_code, envelope.reason || 'An error occurred', envelope.metadata || {});

        // A fatal envelope carries the PHP exception (file, line, backtrace) for a caller
        // the server admits as a developer, and an error_id for everybody else.
        if (error_code === Ajax.ERROR_FATAL) {
            const fatal_error_data = envelope.error || {};

            if (fatal_error_data.file && fatal_error_data.line && fatal_error_data.error) {
                error.code = Ajax.ERROR_PHP_EXCEPTION;
                error.message = fatal_error_data.error;
                error.metadata = {
                    file: fatal_error_data.file,
                    line: fatal_error_data.line,
                    error: fatal_error_data.error,
                    backtrace: fatal_error_data.backtrace || []
                };
                console.error('PHP Exception:', fatal_error_data.error, 'at', fatal_error_data.file + ':' + fatal_error_data.line);
            } else {
                error.message = fatal_error_data.error || 'Fatal error occurred';
                error.metadata = envelope.error;
                console.error('Ajax error response from server:', envelope.error);
            }

            Debugger.log_error({
                message: `Ajax Fatal Error: ${error.message}`,
                type: 'ajax_fatal',
                endpoint: endpoint,
                details: envelope.error,
            });
        }

        // The sign-in this page was rendered for is gone: it expired, or it was ended
        // somewhere else. Announced once per page (Rsx_Session), whichever call noticed.
        if (error_code === Ajax.ERROR_SESSION_EXPIRED || error_code === Ajax.ERROR_AUTH_REQUIRED) {
            console.error('User is no longer authenticated');
            Rsx_Session._announce_expired(error.message);
        }
        if (error_code === Ajax.ERROR_UNAUTHORIZED) {
            console.error('User is unauthorized to perform this action');
        }

        return error;
    }

    /**
     * Extract error message from jQuery XHR object
     * @private
     */
    static _extract_error_message(xhr) {
        if (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) {
            return xhr.responseJSON.message || xhr.responseJSON.error;
        } else if (xhr.responseText) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.message) {
                    return response.message;
                }
            } catch (e) {
                // Not JSON
            }
        }

        return `${xhr.status}: ${xhr.statusText || 'Unknown error'}`;
    }

    /**
     * Parses an AJAX URL into controller and action
     * Supports both /_ajax/ and /_/ URL prefixes
     * @param {string|object|function} url - URL in format '/_ajax/Controller_Name/action_name' or '/_/Controller_Name/action_name', or an object/function with a .path property
     * @returns {Object} Object with {controller: string, action: string}
     * @throws {Error} If URL doesn't start with /_ajax or /_ or has invalid structure
     */
    static ajax_url_to_controller_action(url) {
        // If url is an object or function with a .path property, use that as the URL
        if (url && typeof url === 'object' && url.path) {
            url = url.path;
        } else if (url && typeof url === 'function' && url.path) {
            url = url.path;
        }

        // Validate url is a string
        if (typeof url !== 'string') {
            throw new Error(`URL must be a string or have a .path property, got: ${typeof url}`);
        }

        // Tolerate an ALREADY-rebased portal URL. The channel is applied at call time
        // (Rsx_Portal.internal_url in _send), so a caller that rebased the path
        // itself must not end up double-prefixed or rejected here.
        const portal_prefix = Rsx_Portal.prefix();
        if (portal_prefix !== '' && url.startsWith(portal_prefix + '/_ajax')) {
            url = url.substring(portal_prefix.length);
        }

        if (!url.startsWith('/_ajax') && !url.startsWith('/_/')) {
            throw new Error(`URL must start with /_ajax or /_, got: ${url}`);
        }

        const parts = url.split('/').filter((part) => part !== '');

        if (parts.length < 2) {
            throw new Error(`Invalid AJAX URL structure: ${url}`);
        }

        if (parts.length > 3) {
            throw new Error(`AJAX URL has too many segments: ${url}`);
        }

        const controller = parts[1];
        const action = parts[2] || 'index';

        return { controller, action };
    }

    /**
     * Auto-initialize static properties when class is first loaded
     */
    static on_core_define() {
        Ajax._on_framework_core_init();
    }
}
