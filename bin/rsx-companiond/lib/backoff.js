/**
 * Crash backoff. Pure, so it is tested with no process at all.
 */

/**
 * A run this long ends a crash streak: the next crash counts as the first again. It decides
 * only how the NEXT delay is computed, and bounds nothing. Sixty seconds is long enough that a
 * companion failing on its first call is still a loop, and short enough that a companion that
 * did real work and then crashed is not treated as one.
 */
const STABLE_RUN_MS = 60000;

/**
 * The delay before restart number `streak` of a crash streak: initial, doubled per consecutive
 * crash, never past the ceiling.
 */
function restart_delay_ms(streak, initial_seconds, ceiling_seconds) {
    const initial_ms = initial_seconds * 1000;
    const ceiling_ms = ceiling_seconds * 1000;

    // Past 2^31 the double is meaningless, and every ceiling has already been passed.
    const doublings = Math.min(Math.max(streak, 1) - 1, 31);

    return Math.min(initial_ms * Math.pow(2, doublings), ceiling_ms);
}

/** The streak after a run that lasted run_ms and ended in a crash. */
function next_streak(streak, run_ms) {
    return run_ms >= STABLE_RUN_MS ? 1 : streak + 1;
}

module.exports = { STABLE_RUN_MS, restart_delay_ms, next_streak };
