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

/**
 * One condition as the editor holds it. Every shape the evaluator knows
 * survives the round trip; before, anything that was not a tag or an event came
 * back as a `field` condition, so opening and saving a segment rewrote its
 * custom-field and radius conditions into ones that match nobody.
 *
 * A nested group is not edited by the builder, but it must not be destroyed
 * either: it is kept as it is and shown as a `group` (see `serializeRules`).
 *
 * @param {object} vocabulary the editor's vocabulary; only `fields` is read, for the default of a bare condition
 */
export function normalizeCondition(c, vocabulary) {
    if (Array.isArray(c.conditions)) return { ...c, type: 'group' };

    const type = c.type ?? 'field';
    if (type === 'tag') return { type: 'tag', operator: c.operator ?? 'has', value: c.value ?? '' };
    if (type === 'event') return { type: 'event', operator: c.operator ?? 'has', event: c.event ?? '', within_days: c.within_days ?? null };
    if (type === 'custom') return { type: 'custom', field: c.field ?? '', operator: c.operator ?? 'eq', value: c.value ?? '' };
    // Without a country it stays without one, see normalizeGeoCondition().
    if (type === 'geo') return normalizeGeoCondition(c);
    return { type: 'field', field: c.field ?? vocabulary.fields[0], operator: c.operator ?? 'eq', value: c.value ?? '' };
}

/** The rules a segment was saved with, as the editor's form holds them. */
export function normalizeRules(rules, vocabulary) {
    if (! rules || typeof rules !== 'object') {
        return { match: 'all', conditions: [] };
    }

    return {
        match: rules.match === 'any' ? 'any' : 'all',
        conditions: Array.isArray(rules.conditions) ? rules.conditions.map((c) => normalizeCondition(c, vocabulary)) : [],
    };
}

/** The form's rules as Save sends them. A group goes back exactly as it came; its `type` was only for display. */
export function serializeRules(rules) {
    return {
        ...rules,
        conditions: rules.conditions.map(({ type, ...rest }) => (type === 'group' ? rest : { type, ...rest })),
    };
}
