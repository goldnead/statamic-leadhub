/**
 * A geo condition as the segment editor holds it.
 *
 * `country` is kept only when the condition has one. A condition written
 * without it (an older segment, or one a sync wrote) is evaluated with the
 * evaluator's own default, DE; filling in the first configured country here
 * instead would move the circle as soon as somebody opened and saved the
 * segment — to Austria, on an install that lists AT first.
 *
 * Reads the older key names (`postal_code`, `radius`) too.
 */
export function normalizeGeoCondition(c) {
    const condition = {
        type: 'geo',
        operator: c.operator === 'outside_km' ? 'outside_km' : 'within_km',
        plz: String(c.plz ?? c.postal_code ?? ''),
        value: Number(c.value ?? c.radius ?? 30),
    };

    if (c.country) {
        condition.country = String(c.country);
    }

    return condition;
}

/** The country a condition is evaluated in: its own, or the evaluator's default. */
export const EVALUATOR_DEFAULT_COUNTRY = 'DE';
