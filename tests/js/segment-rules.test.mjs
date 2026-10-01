// Run with `npm test` (node --test, no dependencies).
//
// The segment editor normalises every condition it opens. A geo condition
// written without `country` (older segments, or one a sync wrote) used to
// come back with the FIRST configured country — with AT first in
// `leadhub.postal_codes.countries`, opening and saving a German segment moved
// its circle to Austria without a word. Absent has to stay absent; the
// evaluator's own default (DE) then applies, as it always did.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeGeoCondition } from '../../resources/js/support/segmentRules.js';

test('a geo condition without a country stays without one', () => {
    const normalised = normalizeGeoCondition({ type: 'geo', operator: 'within_km', plz: '89077', value: 30 });

    assert.equal('country' in normalised, false);
    assert.deepEqual(normalised, { type: 'geo', operator: 'within_km', plz: '89077', value: 30 });
});

test('a country that was set is kept', () => {
    assert.equal(normalizeGeoCondition({ type: 'geo', plz: '1010', value: 10, country: 'AT' }).country, 'AT');
});

test('the legacy keys still read', () => {
    assert.deepEqual(
        normalizeGeoCondition({ type: 'geo', operator: 'outside_km', postal_code: '79098', radius: 50 }),
        { type: 'geo', operator: 'outside_km', plz: '79098', value: 50 },
    );
});
