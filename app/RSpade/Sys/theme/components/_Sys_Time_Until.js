/**
 * _Sys_Time_Until - see the .jqhtml. Owns the once-a-second rewrite of the phrase.
 */
class _Sys_Time_Until extends Component {
    on_create() {
        this._ticker = null;
    }

    on_ready() {
        const that = this;

        // on_ready re-fires on every render; one ticker at a time.
        this._stop_ticker();

        this._ticker = setInterval(function () {
            that.$sid('text').text(_Sys_Time_Until.phrase(Rsx_Time.seconds_until(that.args.time)));
        }, 1000);
    }

    on_stop() {
        this._stop_ticker();
    }

    _stop_ticker() {
        if (this._ticker !== null) {
            clearInterval(this._ticker);
            this._ticker = null;
        }
    }

    /**
     * A number of seconds from now, in words. The two largest units that apply, so the
     * phrase changes at a readable pace: seconds under a minute, minutes under an hour,
     * hours and minutes under a day, days and hours beyond.
     *
     * @param {number} seconds Seconds until the moment; zero or negative once it has passed.
     * @returns {string}
     */
    static phrase(seconds) {
        seconds = Math.ceil(seconds);

        if (!(seconds > 0)) {
            return 'any moment now';
        }

        const unit = (count, name) => count + ' ' + name + (count === 1 ? '' : 's');

        if (seconds < 60) {
            return unit(seconds, 'second') + ' from now';
        }

        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return unit(minutes, 'minute') + ' from now';
        }

        const hours = Math.floor(minutes / 60);
        if (hours < 24) {
            const rest = minutes % 60;

            return unit(hours, 'hour') + (rest ? ' ' + unit(rest, 'minute') : '') + ' from now';
        }

        const days = Math.floor(hours / 24);
        const rest = hours % 24;

        return unit(days, 'day') + (rest ? ' ' + unit(rest, 'hour') : '') + ' from now';
    }
}
