/**
 * Formatting a deal value for display.
 *
 * Extracted when the third screen needed it — the board and the contact screen
 * each carried their own byte-identical copy, and a third would have made the
 * currency a thing you change in three places and miss one.
 *
 * The currency is still hard-coded to EUR, exactly as the copies were. That is
 * a known limit, not an oversight: the schema stores `value_estimate` as a bare
 * `decimal(12,2)` with no currency column beside it, so there is nothing to
 * read a currency *from*. Fixing it properly means a column and a migration;
 * pretending to fix it by threading a config value through would produce
 * amounts labelled in a currency the numbers were never entered in. The locale
 * is left to the browser (`undefined`), which is what puts a German CP at
 * "4.200,50 €" and an English one at "€4,200.50".
 *
 * @param {number|string|null|undefined} value
 * @returns {string|null} null when there is no amount, so callers can `v-if` it
 */
export function money(value) {
    if (value === null || value === undefined || value === '') return null;

    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value);
}
