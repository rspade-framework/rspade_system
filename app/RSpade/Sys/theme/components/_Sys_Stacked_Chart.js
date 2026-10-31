/**
 * _Sys_Stacked_Chart
 *
 * See _Sys_Stacked_Chart.jqhtml. The SVG is built here as DOM nodes, on every
 * render, from this.args and the visible keys in this.state; every piece of text
 * in it, and in the tooltip, is set as text, never parsed as markup.
 */
class _Sys_Stacked_Chart extends Component {
    static WIDTH = 640;
    static HEIGHT = 240;
    static PAD = { left: 52, right: 12, top: 12, bottom: 24 };

    on_create() {
        const all = (this.args.series || []).map((s) => s.key);
        this.state.visible = this.args.visible ? all.filter((key) => this.args.visible.includes(key)) : all;
    }

    /**
     * Draw only these series (keys the chart does not have are ignored).
     *
     * @param {string[]} keys
     */
    set_visible(keys) {
        this.state.visible = (this.args.series || []).map((s) => s.key).filter((key) => keys.includes(key));
        this.render();
    }

    on_render() {
        const that = this;
        const g = this._geometry();

        this.$sid('plot').empty().append(this._svg(g));

        this.$sid('plot').off('mousemove._sys_chart mouseleave._sys_chart')
            .on('mousemove._sys_chart', function (event) {
                that._show_tip(g, event);
            })
            .on('mouseleave._sys_chart', function () {
                that.$sid('tip').prop('hidden', true);
            });
    }

    /** The layout every drawing step shares: scales, slot count, the stacked bands. */
    _geometry() {
        const C = _Sys_Stacked_Chart;
        const series = (this.args.series || []).map((s, index) => ({ ...s, index }))
            .filter((s) => this.state.visible.includes(s.key));
        const slots = Math.max(...(this.args.series || []).map((s) => s.values.length), 2);
        const ceilings = this.args.ceilings || [];

        const bands = [];
        const totals = new Array(slots).fill(0);
        for (const s of series) {
            const lower = totals.slice();
            for (let i = 0; i < slots; i++) {
                totals[i] += Number(s.values[i] || 0);
            }
            bands.push({ series: s, lower: lower, upper: totals.slice() });
        }

        const top = Math.max(1, ...totals, ...ceilings.map((c) => Number(c.value) || 0));
        const max = _Sys_Stacked_Chart._nice_ceiling(top * 1.08);
        const plot_w = C.WIDTH - C.PAD.left - C.PAD.right;
        const plot_h = C.HEIGHT - C.PAD.top - C.PAD.bottom;

        return {
            series: series,
            slots: slots,
            bands: bands,
            totals: totals,
            ceilings: ceilings,
            max: max,
            x: (i) => C.PAD.left + (i / (slots - 1)) * plot_w,
            y: (v) => C.PAD.top + plot_h - (v / max) * plot_h,
            plot_w: plot_w,
        };
    }

    /** One SVG element: attributes set as attributes, text as a text node. */
    static _node(name, attrs, text) {
        const node = document.createElementNS('http://www.w3.org/2000/svg', name);
        for (const [key, value] of Object.entries(attrs || {})) {
            node.setAttribute(key, String(value));
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /** The chart as an SVG element. */
    _svg(g) {
        const C = _Sys_Stacked_Chart;
        const node = _Sys_Stacked_Chart._node;
        const unit = this.args.unit ? ' ' + this.args.unit : '';
        const slot_minutes = this.args.slot_minutes || 1;
        const svg = node('svg', { class: '_Sys_Stacked_Chart__svg', viewBox: `0 0 ${C.WIDTH} ${C.HEIGHT}`, role: 'img', 'aria-label': this.args.unit || 'chart' });

        // Grid lines and the y axis: four steps.
        for (let step = 0; step <= 4; step++) {
            const value = (g.max / 4) * step;
            const y = g.y(value);
            svg.appendChild(node('line', { class: '_Sys_Stacked_Chart__grid', x1: C.PAD.left, x2: C.WIDTH - C.PAD.right, y1: y, y2: y }));
            svg.appendChild(node('text', { class: '_Sys_Stacked_Chart__axis', x: C.PAD.left - 6, y: y + 4, 'text-anchor': 'end' }, _Sys_Stacked_Chart._format(value)));
        }

        // The x axis: a tick every 10 slots, counted back from now.
        for (let i = g.slots - 1; i >= 0; i -= 10) {
            const ago = (g.slots - 1 - i) * slot_minutes;
            svg.appendChild(node('text', { class: '_Sys_Stacked_Chart__axis', x: g.x(i), y: C.HEIGHT - 6, 'text-anchor': 'middle' }, ago === 0 ? 'now' : '-' + ago + 'm'));
        }

        // The stacked areas, bottom first.
        for (const band of g.bands) {
            const points = [];
            for (let i = 0; i < g.slots; i++) points.push(`${g.x(i)},${g.y(band.upper[i])}`);
            for (let i = g.slots - 1; i >= 0; i--) points.push(`${g.x(i)},${g.y(band.lower[i])}`);
            svg.appendChild(node('polygon', { class: `_Sys_Stacked_Chart__area _Sys_Stacked_Chart__area--${band.series.index % 4 + 1}`, points: points.join(' ') }));
        }

        // The ceilings, dashed, labelled at the right.
        for (const ceiling of g.ceilings) {
            const y = g.y(Number(ceiling.value) || 0);
            svg.appendChild(node('line', { class: '_Sys_Stacked_Chart__ceiling', x1: C.PAD.left, x2: C.WIDTH - C.PAD.right, y1: y, y2: y }));
            svg.appendChild(node('text', { class: '_Sys_Stacked_Chart__ceiling-label', x: C.WIDTH - C.PAD.right - 4, y: y - 4, 'text-anchor': 'end' },
                ceiling.label + ' ' + _Sys_Stacked_Chart._format(ceiling.value) + unit));
        }

        return svg;
    }

    _show_tip(g, event) {
        const C = _Sys_Stacked_Chart;
        const $plot = this.$sid('plot');
        const rect = $plot[0].getBoundingClientRect();
        if (rect.width === 0) return;

        const view_x = ((event.clientX - rect.left) / rect.width) * C.WIDTH;
        const i = Math.max(0, Math.min(g.slots - 1, Math.round(((view_x - C.PAD.left) / g.plot_w) * (g.slots - 1))));
        const ago = (g.slots - 1 - i) * (this.args.slot_minutes || 1);
        const unit = this.args.unit ? ' ' + this.args.unit : '';
        const $tip = this.$sid('tip').empty();

        $('<div class="_Sys_Stacked_Chart__tip-head"></div>').text(ago === 0 ? 'this minute' : ago + ' min ago').appendTo($tip);
        for (const band of g.bands.slice().reverse()) {
            const $row = $('<div></div>').appendTo($tip);
            $('<span></span>').addClass('_Sys_Stacked_Chart__swatch _Sys_Stacked_Chart__swatch--' + (band.series.index % 4 + 1)).appendTo($row);
            $('<span></span>').text(band.series.label + ': ' + _Sys_Stacked_Chart._format(band.upper[i] - band.lower[i]) + unit).appendTo($row);
        }
        $('<div class="_Sys_Stacked_Chart__tip-total"></div>').text('Total: ' + _Sys_Stacked_Chart._format(g.totals[i]) + unit).appendTo($tip);

        $tip.css({ left: ((g.x(i) / C.WIDTH) * rect.width) + 'px' }).prop('hidden', false);
    }

    /** A round axis top at or above the value: 1, 2 or 5 times a power of ten. */
    static _nice_ceiling(value) {
        const power = Math.pow(10, Math.floor(Math.log10(value)));
        for (const step of [1, 2, 5, 10]) {
            if (step * power >= value) return step * power;
        }
        return 10 * power;
    }

    static _format(value) {
        const n = Number(value) || 0;
        if (n >= 100) return String(Math.round(n));
        if (n >= 10) return n.toFixed(1).replace(/\.0$/, '');
        return n.toFixed(2).replace(/\.?0+$/, '') || '0';
    }
}
