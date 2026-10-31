/**
 * Rsx_Time - Datetime handling for RSpade (JavaScript)
 *
 * Datetimes represent specific moments in time. They always have a time component
 * and are timezone-aware. Stored in UTC, displayed in user's timezone.
 *
 * All times stored internally as Date objects (UTC).
 * Uses native Date API and Intl.DateTimeFormat - no external libraries required.
 *
 * Core Principles:
 * - All datetimes stored in database as UTC
 * - All serialization uses ISO 8601 format
 * - User timezone stored per user
 * - Formatting happens on-demand
 * - PHP and JS APIs are parallel (same method names)
 * - Datetime functions THROW if passed a date-only string
 * - Use Rsx_Date for calendar dates without time components
 *
 * See: php artisan rsx:man time
 */
class Rsx_Time {

    // =========================================================================
    // CONFIGURATION (set by framework on page load)
    // =========================================================================

    /**
     * User's preferred timezone (IANA identifier)
     * @type {string}
     */
    static _user_timezone = 'America/Chicago';

    /**
     * Milliseconds offset to adjust for server/client clock difference
     * @type {number}
     */
    static _server_time_offset = 0;

    /**
     * Whether server time has been synced via AJAX
     * Server time is only synced on first AJAX response or when timezone changes
     * @type {boolean}
     */
    static _ajax_synced = false;

    /**
     * Whether this page stopped adopting the zone from Ajax envelopes: set when the browser's
     * zone changed under an open page (Rsx_Timezone_Cookie), so one screen never shows times
     * in two zones. The page's next full load renders in the new one.
     * @type {boolean}
     */
    static _timezone_frozen = false;

    /**
     * Framework initialization hook
     * Reads initial time configuration from window.rsxapp on page load
     */
    static _on_framework_core_init() {
        if (window.rsxapp) {
            // Set timezone from rsxapp (always set, may be logged-in user's or default)
            if (window.rsxapp.user_timezone) {
                this._user_timezone = window.rsxapp.user_timezone;
            }

            // Calculate initial server time offset from page load time
            if (window.rsxapp.server_time) {
                const server_ms = this.parse(window.rsxapp.server_time).getTime();
                const client_ms = Date.now();
                this._server_time_offset = server_ms - client_ms;
            }
        }
    }

    /**
     * Sync from AJAX response data
     * Only updates server time offset on first AJAX call or when timezone changes
     * Called by Ajax.js after receiving responses
     *
     * @param {Object} config
     * @param {string} [config.user_timezone] - User's IANA timezone
     * @param {string} [config.server_time] - Server's current time (ISO 8601)
     */
    static sync_from_ajax(config) {
        if (!config) return;

        const timezone_changed = !this._timezone_frozen && config.user_timezone && config.user_timezone !== this._user_timezone;

        // Adopt the envelope's zone - unless this page has frozen its zone
        if (config.user_timezone && !this._timezone_frozen) {
            this._user_timezone = config.user_timezone;
        }

        // Only sync server time on first AJAX response or timezone change
        if (config.server_time && (!this._ajax_synced || timezone_changed)) {
            const server_ms = this.parse(config.server_time).getTime();
            const client_ms = Date.now();
            this._server_time_offset = server_ms - client_ms;
            this._ajax_synced = true;
        }
    }

    /**
     * Keep this page's zone until its next full load (Rsx_Timezone_Cookie calls it when the
     * browser's zone changes under an open page).
     */
    static _freeze_timezone() {
        this._timezone_frozen = true;
    }

    // =========================================================================
    // CURRENT TIME
    // =========================================================================

    /**
     * Get current time as Date (adjusted for server clock)
     *
     * @returns {Date}
     */
    static now() {
        return new Date(Date.now() + this._server_time_offset);
    }

    /**
     * Get current time as ISO 8601 UTC string
     *
     * @returns {string}
     */
    static now_iso() {
        return this.to_iso(this.now());
    }

    /**
     * Get current time as Unix milliseconds
     *
     * @returns {number}
     */
    static now_ms() {
        return this.now().getTime();
    }

    // =========================================================================
    // PARSING & VALIDATION
    // =========================================================================

    /**
     * Regex pattern for date-only strings
     * @type {RegExp}
     */
    static DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

    /**
     * Check if input is a valid datetime (not a date-only value)
     *
     * @param {*} input
     * @returns {boolean}
     */
    static is_datetime(input) {
        if (input instanceof Date) {
            return true;
        }

        if (typeof input === 'number') {
            return true; // Timestamps are datetimes
        }

        if (typeof input === 'string') {
            // Date-only strings are NOT datetimes
            if (this.DATE_ONLY_PATTERN.test(input)) {
                return false;
            }

            // Has time component (T separator or HH:MM pattern)
            if (input.includes('T') || /\d{2}:\d{2}/.test(input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse any reasonable datetime input to Date object
     * THROWS if passed a date-only string - use Rsx_Date for dates
     *
     * Accepts:
     * - Date instance (returned as copy)
     * - ISO 8601 string
     * - Unix timestamp (ms or seconds - auto-detected)
     * - null/undefined (returns null)
     *
     * @param {*} input
     * @returns {Date|null}
     * @throws {Error} If input is a date-only string
     */
    static parse(input) {
        if (input == null || input === '') {
            return null;
        }

        // REJECT date-only strings - these should use Rsx_Date
        if (typeof input === 'string' && this.DATE_ONLY_PATTERN.test(input)) {
            throw new Error(
                `Rsx_Time.parse() received date-only string '${input}'. ` +
                "Use Rsx_Date.parse() for dates without time components."
            );
        }

        if (input instanceof Date) {
            return new Date(input.getTime());
        }

        if (typeof input === 'number') {
            // Detect milliseconds vs seconds (after year 2001, ms > 10 digits)
            if (input > 10000000000) {
                return new Date(input);
            }
            return new Date(input * 1000);
        }

        if (typeof input === 'string') {
            // ISO 8601 or parseable string
            const date = new Date(input);
            if (!isNaN(date.getTime())) {
                return date;
            }
        }

        return null;
    }

    // =========================================================================
    // TIMEZONE HANDLING
    // =========================================================================

    /**
     * Get current user's timezone
     *
     * @returns {string} IANA timezone identifier
     */
    static get_user_timezone() {
        return this._user_timezone;
    }

    /**
     * Format time in a specific timezone
     * Uses Intl.DateTimeFormat for proper timezone conversion
     *
     * @param {*} time - Parseable time input
     * @param {Object} format_options - Intl.DateTimeFormat options
     * @param {string} [timezone] - IANA timezone (defaults to user's)
     * @returns {string}
     */
    static format_in_timezone(time, format_options, timezone) {
        const date = this.parse(time);
        if (!date) return '';

        const options = {
            ...format_options,
            timeZone: timezone || this._user_timezone
        };

        return new Intl.DateTimeFormat('en-US', options).format(date);
    }

    /**
     * Get timezone abbreviation for a time (e.g., "CST", "CDT")
     * Handles DST correctly based on the actual date
     *
     * @param {*} time
     * @param {string} [timezone]
     * @returns {string}
     */
    static get_timezone_abbr(time, timezone) {
        const date = this.parse(time);
        if (!date) return '';

        const tz = timezone || this._user_timezone;
        const options = { timeZone: tz, timeZoneName: 'short' };
        const parts = new Intl.DateTimeFormat('en-US', options).formatToParts(date);
        const tz_part = parts.find(p => p.type === 'timeZoneName');
        return tz_part ? tz_part.value : '';
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    // =========================================================================
    // A DAY AND A CLOCK IN A NAMED ZONE (rsx:man time_value_design)
    // =========================================================================

    /**
     * The calendar date an instant falls on in a zone ("2026-10-29") - never a slice of the
     * ISO string, which is the UTC date. Twin of Rsx_Time::local_date().
     *
     * @param {*} time
     * @param {string} zone - IANA timezone identifier
     * @returns {string|null}
     */
    static local_date(time, zone) {
        const date = this.parse(time);
        if (!date) return null;
        const p = this._zone_parts(date.getTime(), zone);
        return p.year + '-' + p.month + '-' + p.day;
    }

    /**
     * The wall-clock time an instant shows in a zone ("22:30"). Twin of Rsx_Time::local_clock().
     *
     * @param {*} time
     * @param {string} zone - IANA timezone identifier
     * @returns {string|null}
     */
    static local_clock(time, zone) {
        const date = this.parse(time);
        if (!date) return null;
        const p = this._zone_parts(date.getTime(), zone);
        return p.hour + ':' + p.minute;
    }

    /**
     * The instant meant by a DATE and a wall CLOCK typed in a zone that need not be the
     * browser's. Twin of Rsx_Time::combine_date_and_time(): an ambiguous fall-back clock is its
     * FIRST occurrence, a clock a spring-forward jump skips resolves to the instant after it.
     *
     * @param {string} date - "YYYY-MM-DD"
     * @param {string|null} clock - "HH:MM" or "HH:MM:SS"; null or '' answers null
     * @param {string} zone - IANA timezone identifier
     * @returns {string|null} ISO 8601 UTC
     */
    static combine_date_and_time(date, clock, zone) {
        if (clock === null || clock === undefined || clock === '') return null;

        const d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
        const c = /^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/.exec(clock);
        if (!d || !c) {
            throw new Error(`Rsx_Time.combine_date_and_time() needs a YYYY-MM-DD date and an HH:MM clock, got '${date}' and '${clock}'.`);
        }

        // The wall clock read as if it were UTC, then shifted by the zone's offset: the offset
        // from a day before is tried first (the first occurrence of an ambiguous clock), then
        // the one from a day after; a clock neither reproduces does not exist (the jump).
        const wall = Date.UTC(+d[1], +d[2] - 1, +d[3], +c[1], +c[2], c[3] ? +c[3] : 0);
        const day = 86400000;
        const before = wall - this._zone_offset_ms(wall - day, zone);
        if (this._zone_wall_ms(before, zone) === wall) return this.to_iso(new Date(before));
        const after = wall - this._zone_offset_ms(wall + day, zone);
        if (this._zone_wall_ms(after, zone) === wall) return this.to_iso(new Date(after));
        return this.to_iso(new Date(before));
    }

    /**
     * The instant a date begins in a zone - the inclusive lower bound of a date window.
     * Twin of Rsx_Time::day_start_utc().
     *
     * @param {string} date - "YYYY-MM-DD"
     * @param {string} zone
     * @returns {string} ISO 8601 UTC
     */
    static day_start_utc(date, zone) {
        return this.combine_date_and_time(date, '00:00', zone);
    }

    /**
     * The instant the day after a date begins in a zone - the EXCLUSIVE upper bound of a date
     * window ending on that date. Twin of Rsx_Time::day_after_utc().
     *
     * @param {string} date - "YYYY-MM-DD"
     * @param {string} zone
     * @returns {string} ISO 8601 UTC
     */
    static day_after_utc(date, zone) {
        return this.day_start_utc(Rsx_Date.add_days(date, 1), zone);
    }

    /**
     * The wall-clock fields of an instant in a zone, zero-padded strings. Throws a RangeError
     * for an unknown zone (Intl's own refusal).
     */
    static _zone_parts(ms, zone) {
        const parts = {};
        const format = new Intl.DateTimeFormat('en-US', {
            timeZone: zone, hourCycle: 'h23',
            year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
        });
        for (const part of format.formatToParts(new Date(ms))) {
            parts[part.type] = part.value;
        }
        return parts;
    }

    /** The wall clock an instant shows in a zone, as milliseconds read as if UTC. */
    static _zone_wall_ms(ms, zone) {
        const p = this._zone_parts(ms, zone);
        return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second);
    }

    /** The zone's offset from UTC at an instant, in milliseconds (whole seconds). */
    static _zone_offset_ms(ms, zone) {
        const whole = Math.floor(ms / 1000) * 1000;
        return this._zone_wall_ms(whole, zone) - whole;
    }

    /**
     * Convert to ISO 8601 UTC string
     *
     * @param {*} time
     * @returns {string|null}
     */
    static to_iso(time) {
        const date = this.parse(time);
        if (!date) return null;
        return date.toISOString();
    }

    /**
     * Convert to Unix milliseconds
     *
     * @param {*} time
     * @returns {number|null}
     */
    static to_ms(time) {
        const date = this.parse(time);
        if (!date) return null;
        return date.getTime();
    }

    // =========================================================================
    // DURATION HANDLING
    // =========================================================================

    /**
     * Calculate seconds between two times
     *
     * @param {*} start
     * @param {*} end
     * @returns {number} Seconds (negative if end < start)
     */
    static diff_seconds(start, end) {
        const start_date = this.parse(start);
        const end_date = this.parse(end);
        if (!start_date || !end_date) return 0;
        return Math.floor((end_date.getTime() - start_date.getTime()) / 1000);
    }

    /**
     * Seconds until a future time (negative if past)
     *
     * @param {*} time
     * @returns {number}
     */
    static seconds_until(time) {
        return this.diff_seconds(this.now(), time);
    }

    /**
     * Seconds since a past time (negative if future)
     *
     * @param {*} time
     * @returns {number}
     */
    static seconds_since(time) {
        return this.diff_seconds(time, this.now());
    }

    /**
     * Format duration as human-readable string
     *
     * @param {number} seconds
     * @param {boolean} [short=false] - Use short format ("2h 30m") vs long
     * @returns {string}
     */
    static duration_to_human(seconds, short = false) {
        const negative = seconds < 0;
        seconds = Math.abs(Math.floor(seconds));

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        const parts = [];

        if (short) {
            if (days > 0) parts.push(`${days}d`);
            if (hours > 0) parts.push(`${hours}h`);
            if (minutes > 0) parts.push(`${minutes}m`);
            if (secs > 0 && parts.length === 0) parts.push(`${secs}s`);
            const result = parts.join(' ') || '0s';
            return negative ? '-' + result : result;
        } else {
            if (days > 0) parts.push(days + ' ' + (days === 1 ? 'day' : 'days'));
            if (hours > 0) parts.push(hours + ' ' + (hours === 1 ? 'hour' : 'hours'));
            if (minutes > 0) parts.push(minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes'));
            if (secs > 0 && parts.length === 0) parts.push(secs + ' ' + (secs === 1 ? 'second' : 'seconds'));

            let result;
            if (parts.length > 1) {
                const last = parts.pop();
                result = parts.join(', ') + ' and ' + last;
            } else {
                result = parts[0] || '0 seconds';
            }
            return negative ? '-' + result : result;
        }
    }

    /**
     * Format as relative time ("2 hours ago", "in 3 days")
     *
     * @param {*} time
     * @returns {string}
     */
    static relative(time) {
        const seconds = this.seconds_since(time);
        const abs_seconds = Math.abs(seconds);
        const is_past = seconds >= 0;

        let value, unit;

        if (abs_seconds < 60) {
            return is_past ? 'just now' : 'in a moment';
        } else if (abs_seconds < 3600) {
            value = Math.floor(abs_seconds / 60);
            unit = value === 1 ? 'minute' : 'minutes';
        } else if (abs_seconds < 86400) {
            value = Math.floor(abs_seconds / 3600);
            unit = value === 1 ? 'hour' : 'hours';
        } else if (abs_seconds < 604800) {
            value = Math.floor(abs_seconds / 86400);
            unit = value === 1 ? 'day' : 'days';
        } else if (abs_seconds < 2592000) {
            value = Math.floor(abs_seconds / 604800);
            unit = value === 1 ? 'week' : 'weeks';
        } else if (abs_seconds < 31536000) {
            value = Math.floor(abs_seconds / 2592000);
            unit = value === 1 ? 'month' : 'months';
        } else {
            value = Math.floor(abs_seconds / 31536000);
            unit = value === 1 ? 'year' : 'years';
        }

        return is_past ? `${value} ${unit} ago` : `in ${value} ${unit}`;
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * Add seconds to time
     *
     * @param {*} time
     * @param {number} seconds
     * @returns {Date}
     */
    static add(time, seconds) {
        const date = this.parse(time);
        if (!date) throw new Error('Cannot parse time');
        return new Date(date.getTime() + seconds * 1000);
    }

    /**
     * Subtract seconds from time
     *
     * @param {*} time
     * @param {number} seconds
     * @returns {Date}
     */
    static subtract(time, seconds) {
        return this.add(time, -seconds);
    }

    // =========================================================================
    // COMPARISON
    // =========================================================================

    /**
     * Check if time is in the past
     *
     * @param {*} time
     * @returns {boolean}
     */
    static is_past(time) {
        const date = this.parse(time);
        if (!date) return false;
        return date.getTime() < this.now().getTime();
    }

    /**
     * Check if time is in the future
     *
     * @param {*} time
     * @returns {boolean}
     */
    static is_future(time) {
        const date = this.parse(time);
        if (!date) return false;
        return date.getTime() > this.now().getTime();
    }

    /**
     * Check if time is today (in user's timezone)
     *
     * @param {*} time
     * @returns {boolean}
     */
    static is_today(time) {
        const date = this.parse(time);
        if (!date) return false;
        return this.format_date(date) === this.format_date(this.now());
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    /**
     * Format as date: "Dec 24, 2024"
     *
     * @param {*} time
     * @param {string} [timezone]
     * @returns {string}
     */
    static format_date(time, timezone) {
        return this.format_in_timezone(time, {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }, timezone);
    }

    /**
     * Format as time: "3:30 PM"
     *
     * @param {*} time
     * @param {string} [timezone]
     * @returns {string}
     */
    static format_time(time, timezone) {
        return this.format_in_timezone(time, {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        }, timezone);
    }

    /**
     * Format as datetime: "Dec 24, 2024, 3:30 PM"
     *
     * @param {*} time
     * @param {string} [timezone]
     * @returns {string}
     */
    static format_datetime(time, timezone) {
        return this.format_in_timezone(time, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        }, timezone);
    }

    /**
     * Format as datetime with timezone: "Dec 24, 2024, 3:30 PM CST"
     *
     * @param {*} time
     * @param {string} [timezone]
     * @returns {string}
     */
    static format_datetime_with_tz(time, timezone) {
        const formatted = this.format_datetime(time, timezone);
        const abbr = this.get_timezone_abbr(time, timezone);
        return `${formatted} ${abbr}`;
    }

    // =========================================================================
    // LIVE UPDATES (Countdown/Countup)
    // =========================================================================

    /**
     * Create a live countdown display
     * Updates every second until target time is reached
     *
     * @param {HTMLElement|jQuery} element - Target element to update
     * @param {*} target_time - Time to count down to
     * @param {Object} [options]
     * @param {boolean} [options.short=false] - Use short format
     * @param {Function} [options.on_complete] - Callback when countdown reaches zero
     * @returns {{stop: Function}} Control object with stop method
     */
    static countdown(element, target_time, options = {}) {
        const $el = $(element);
        const short = options.short ?? false;

        const update = () => {
            const seconds = this.seconds_until(target_time);
            if (seconds <= 0) {
                $el.text(short ? '0s' : '0 seconds');
                if (options.on_complete) {
                    options.on_complete();
                }
                return;
            }
            $el.text(this.duration_to_human(seconds, short));
        };

        update();
        const interval = setInterval(update, 1000);

        return {
            stop: () => clearInterval(interval)
        };
    }

    /**
     * Create a live countup display
     * Updates every second showing elapsed time since start
     *
     * @param {HTMLElement|jQuery} element - Target element to update
     * @param {*} start_time - Time to count up from
     * @param {Object} [options]
     * @param {boolean} [options.short=false] - Use short format
     * @returns {{stop: Function}} Control object with stop method
     */
    static countup(element, start_time, options = {}) {
        const $el = $(element);
        const short = options.short ?? false;

        const update = () => {
            const seconds = this.seconds_since(start_time);
            $el.text(this.duration_to_human(Math.max(0, seconds), short));
        };

        update();
        const interval = setInterval(update, 1000);

        return {
            stop: () => clearInterval(interval)
        };
    }

    // =========================================================================
    // COMPONENT EXTRACTORS
    // =========================================================================

    /**
     * Format component with validation (internal helper). Uses user's timezone.
     */
    static _format_component(time, options) {
        return this.parse(time) ? this.format_in_timezone(time, options) : null;
    }

    /**
     * Get day of month (1-31). Uses user's timezone.
     */
    static day(time) {
        const v = this._format_component(time, { day: 'numeric' });
        return v ? parseInt(v, 10) : null;
    }

    /**
     * Get day of week (0=Sunday, 6=Saturday). Uses user's timezone.
     */
    static dow(time) {
        const dayName = this._format_component(time, { weekday: 'short' });
        if (!dayName) return null;
        const dayMap = { 'Sun': 0, 'Mon': 1, 'Tue': 2, 'Wed': 3, 'Thu': 4, 'Fri': 5, 'Sat': 6 };
        return dayMap[dayName] ?? null;
    }

    /**
     * Get full day name ("Monday", "Tuesday", etc.). Uses user's timezone.
     */
    static dow_human(time) {
        return this._format_component(time, { weekday: 'long' }) ?? '';
    }

    /**
     * Get short day name ("Mon", "Tue", etc.). Uses user's timezone.
     */
    static dow_short(time) {
        return this._format_component(time, { weekday: 'short' }) ?? '';
    }

    /**
     * Get month (1-12). Uses user's timezone.
     */
    static month(time) {
        const v = this._format_component(time, { month: '2-digit' });
        return v ? parseInt(v, 10) : null;
    }

    /**
     * Get full month name ("January", "February", etc.). Uses user's timezone.
     */
    static month_human(time) {
        return this._format_component(time, { month: 'long' }) ?? '';
    }

    /**
     * Get short month name ("Jan", "Feb", etc.). Uses user's timezone.
     */
    static month_human_short(time) {
        return this._format_component(time, { month: 'short' }) ?? '';
    }

    /**
     * Get year (e.g., 2025). Uses user's timezone.
     */
    static year(time) {
        const v = this._format_component(time, { year: 'numeric' });
        return v ? parseInt(v, 10) : null;
    }

    /**
     * Get hour (0-23). Uses user's timezone.
     */
    static hour(time) {
        const v = this._format_component(time, { hour: '2-digit', hour12: false });
        if (!v) return null;
        const hour = parseInt(v, 10);
        return hour === 24 ? 0 : hour; // "24" returned for midnight in some locales
    }

    /**
     * Get minute (0-59). Uses user's timezone.
     */
    static minute(time) {
        const v = this._format_component(time, { minute: '2-digit' });
        return v ? parseInt(v, 10) : null;
    }
}
