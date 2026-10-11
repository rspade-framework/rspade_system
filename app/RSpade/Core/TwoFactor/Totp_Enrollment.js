/**
 * Totp_Enrollment
 *
 * See Totp_Enrollment.jqhtml for the contract. This file owns three things: fetching the
 * seed, submitting the proof, and the one-way move into the recovery-code reveal.
 *
 * WHY THE CODES LIVE IN this.state AND NOT this.data. this.data is the Ajax-loaded picture
 * of the world and is writable only in on_create/on_load; the recovery codes arrive from a
 * click, long after the load, and they are the answer to something the USER did. That is
 * exactly what this.state is for. It also means a reload() cannot resurrect them, which is
 * correct: they are shown once.
 *
 * THE CONFIRMATION FAILURE IS EXPECTED INPUT. A mistyped code, a phone whose clock has
 * drifted, a seed that was scanned twice - the server answers ERROR_VALIDATION with a
 * user-safe message, which is rendered beside the input. The parked seed survives, so the
 * retry is the same enrollment rather than a new one.
 */
class Totp_Enrollment extends Component {
    on_create() {
        this.data.setup = null;

        this.state = {
            // The plaintext recovery codes, once the factor is confirmed. Non-null IS the
            // reveal state.
            recovery_codes: null,

            // The inline message under the code input, cleared on every fresh attempt.
            error: null,

            // Whether the copy button has been used - a label, not a state machine.
            copied: false,
        };
    }

    async on_load() {
        this.data.setup = await Rsx_Two_Factor.controller().totp_begin();
    }

    on_render() {
        const that = this;

        const $confirm = this.$sid('confirm');
        if ($confirm.exists()) {
            $confirm.click_async(async function () {
                await that._confirm();
            });
        }

        // Enter in the code boxes confirms. The widget swallows the key (preventDefault)
        // while boxes are empty, so only a complete code arrives here unprevented.
        const code = this.sid('code');
        if (code) {
            code.$.off('keydown.tfa').on('keydown.tfa', async function (e) {
                if (e.key === 'Enter' && !e.isDefaultPrevented()) {
                    await that._confirm();
                }
            });

            // A refused code leaves the boxes marked until the next render.
            code.$.toggleClass('is-invalid', !!this.state.error);

            // The cursor starts in the boxes, and returns there after a refusal (a refusal
            // re-renders). focus() is buffered, so it does not matter that the input may
            // not be ready yet.
            code.focus();
        }

        const $copy = this.$sid('copy');
        if ($copy.exists()) {
            $copy.click_async(async function () {
                await that._copy();
            });
        }

        const $acknowledge = this.$sid('acknowledge');
        if ($acknowledge.exists()) {
            $acknowledge.off('click.tfa').on('click.tfa', function () {
                that.trigger('enrolled');
            });
        }
    }

    /**
     * Prove a live code and, on success, move to the reveal.
     */
    async _confirm() {
        const code = this.sid('code').val();

        // A blank answer is not an attempt.
        if (code === '') {
            return;
        }

        this.state.error = null;

        let result;

        try {
            result = await Rsx_Two_Factor.controller().totp_confirm({ code: code });
        } catch (e) {
            // The ONE catch: a wrong or expired code is what this screen exists to handle.
            // The message is the server's, phrased for the person at the keyboard.
            this.state.error = e.message ? e.message : 'That code is not valid.';
            // The render rebuilds the boxes empty and puts the cursor back in them.
            this.render();
            return;
        }

        this.state.recovery_codes = result.recovery_codes;
        this.state.copied = false;
        this.render();
    }

    /**
     * Put the codes on the clipboard, one per line.
     */
    async _copy() {
        await navigator.clipboard.writeText(this.state.recovery_codes.join('\n'));

        this.state.copied = true;
        this.render();
    }
}
