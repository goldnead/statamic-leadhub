/**
 * Normalizing what the CP <DatePicker> gives us.
 *
 * The Statamic CP date picker is built on reka-ui: its v-model is an
 * `@internationalized/date` DateValue OBJECT, never a string. Bound straight to
 * a request payload it serializes to
 *
 *     {"calendar":{"identifier":"gregory"},"era":"AD","year":2026,"month":7,"day":19}
 *
 * and every Laravel `date` rule rejects it with "Not a valid date." That is
 * what made follow-ups impossible to create from the UI: a 422 the page never
 * showed. Anything bound to a DatePicker must run through here before it is
 * sent.
 */

import { CalendarDateTime } from '@internationalized/date';

const pad = (n) => String(n).padStart(2, '0');

/**
 * Return a `YYYY-MM-DD HH:mm:ss` string for whatever the picker produced, or
 * null when there is no usable value.
 *
 * @param {*} value  DateValue object, Date, ISO string, or null
 * @returns {string|null}
 */
export function toDateTimeString(value) {
    if (value === null || value === undefined || value === '') return null;

    if (typeof value === 'string') return value;

    if (value instanceof Date) {
        return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} `
            + `${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
    }

    if (typeof value !== 'object') return String(value);

    // A DateValue carries its wall-clock parts directly. Reading them is more
    // reliable than toString(), which differs between CalendarDate,
    // CalendarDateTime and ZonedDateTime.
    if (typeof value.year === 'number' && typeof value.month === 'number' && typeof value.day === 'number') {
        return `${value.year}-${pad(value.month)}-${pad(value.day)} `
            + `${pad(value.hour ?? 0)}:${pad(value.minute ?? 0)}:${pad(value.second ?? 0)}`;
    }

    // Wrapper shapes, and anything that knows how to render itself.
    for (const key of ['iso', 'date', 'datetime', 'value']) {
        if (typeof value[key] === 'string') return value[key];
    }

    if (typeof value.toDate === 'function') {
        try {
            return toDateTimeString(value.toDate());
        } catch (e) {
            // ZonedDateTime#toDate() needs no argument; CalendarDate#toDate()
            // wants a timezone. Fall through to toString() rather than throw.
        }
    }

    if (typeof value.toString === 'function') {
        const s = value.toString();
        if (s && s !== '[object Object]') return s;
    }

    return null;
}

/** True when the picker holds something we could actually submit. */
export function hasDateValue(value) {
    return toDateTimeString(value) !== null;
}

/**
 * The other direction: a stored string into what the picker can hold.
 *
 * `<DatePicker>` hands its value straight to reka-ui, which calls `.copy()` on
 * it during setup. A string has no `.copy`, so binding `'2026-08-28 14:00'`
 * throws before the component ever renders — and the field simply is not there,
 * with only its label left behind. That is what the edit screens did to every
 * task that had a due date.
 *
 * `@internationalized/date` is a dependency of this addon for exactly this, and
 * bundling it is the right trade: it is a leaf library with no shared state, so
 * a second copy costs bytes and nothing else. Hand-rolling a DateValue would be
 * forking a core dependency, which is the thing this family does not do.
 *
 * @param {*} value  `Y-m-d H:i[:s]`, an ISO string, a Date, or something that
 *                   is already a DateValue
 * @returns {CalendarDateTime|null}
 */
export function toDateValue(value) {
    if (value === null || value === undefined || value === '') return null;

    // Already one. Passing it through is what makes this safe to call twice.
    if (typeof value === 'object' && typeof value.copy === 'function') return value;

    if (value instanceof Date) {
        return new CalendarDateTime(
            value.getFullYear(), value.getMonth() + 1, value.getDate(),
            value.getHours(), value.getMinutes(), value.getSeconds(),
        );
    }

    if (typeof value !== 'string') return null;

    // `Y-m-d H:i:s`, `Y-m-d H:i`, `Y-m-dTH:i:s`, `Y-m-d`. Anything with a zone
    // suffix is trimmed: the picker shows wall-clock time, and pretending to
    // carry a zone it will not preserve is worse than dropping it.
    const m = value.trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/);

    if (! m) return null;

    const [, y, mo, d, h, mi, s] = m;

    return new CalendarDateTime(+y, +mo, +d, +(h ?? 0), +(mi ?? 0), +(s ?? 0));
}
