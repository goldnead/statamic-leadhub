// Run with `npm test` (node --test, no dependencies).
//
// Opening a segment in the editor and saving it must hand the server the same
// rules it started with. The editor used to read every condition that was not
// a tag or an event as a plain `field` condition, so a custom-field, a radius
// (`geo`) or a nested-group condition came back as something that matches
// nobody, and the segment was silently empty after one Save.
//
// The round trip: what the CP sends as `segment.rules` -> the editor's form
// (`normalizeRules`) -> the payload of Save (`serializeRules`).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeRules, serializeRules } from '../../resources/js/support/segmentRules.js';

const vocabulary = { fields: ['email', 'source'] };

function roundTrip(rules) {
    return serializeRules(normalizeRules(rules, vocabulary));
}

test('a custom-field condition survives open and save', () => {
    const rules = {
        match: 'all',
        conditions: [{ type: 'custom', field: 'instrument', operator: 'eq', value: 'bass' }],
    };

    assert.deepEqual(roundTrip(rules), rules);
});

test('a radius (geo) condition survives open and save', () => {
    const rules = {
        match: 'all',
        conditions: [{ type: 'geo', operator: 'within_km', plz: '89077', value: 30, country: 'DE' }],
    };

    assert.deepEqual(roundTrip(rules), rules);
});

test('a nested group survives open and save, unedited', () => {
    const rules = {
        match: 'any',
        conditions: [
            { type: 'field', field: 'email', operator: 'contains', value: '@example.com' },
            {
                match: 'all',
                conditions: [
                    { type: 'tag', operator: 'has', value: 'chor' },
                    { type: 'custom', field: 'stimmgruppe', operator: 'eq', value: 'alt' },
                ],
            },
        ],
    };

    assert.deepEqual(roundTrip(rules), rules);
});

test('the other condition types keep working in the same segment', () => {
    const rules = {
        match: 'all',
        conditions: [
            { type: 'field', field: 'source', operator: 'eq', value: 'newsletter' },
            { type: 'tag', operator: 'has', value: 'chor' },
            { type: 'event', operator: 'has', event: 'purchase', within_days: 30 },
        ],
    };

    assert.deepEqual(roundTrip(rules), rules);
});

test('the editor shows a group as a group, but the payload carries no editor-only type', () => {
    const form = normalizeRules({ match: 'all', conditions: [{ match: 'any', conditions: [] }] }, vocabulary);

    assert.equal(form.conditions[0].type, 'group');
    assert.equal('type' in serializeRules(form).conditions[0], false);
});
