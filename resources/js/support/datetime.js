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
