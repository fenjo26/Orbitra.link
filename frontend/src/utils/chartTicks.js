// Tick labels of a percent axis, shared by every chart that draws one.
//
// Chart.js hands a tick callback raw tick values, and they carry float noise
// whenever the axis starts at the data minimum rather than at zero: the ticks
// of a 2.8…3.8 axis come out as 2.8000000000000003, 3.3000000000000003, … and
// a bare `${value}%` prints every digit. The scale's own number formatter is
// applied first: it takes its digits from the tick step (0.1 → one decimal,
// 0.25 → two) and follows the chart locale, and only the percent sign is ours.
import { Ticks } from 'chart.js';

// `this` is the scale — Chart.js calls the callback on it — so this has to stay
// a plain function, never an arrow one.
export function percentTick(value, index, ticks) {
    return `${Ticks.formatters.numeric.call(this, value, index, ticks)}%`;
}
