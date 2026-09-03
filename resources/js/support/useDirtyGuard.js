import { onBeforeUnmount, watch } from 'vue';

/**
 * NOT IN USE — and read this before wiring it up. 03.09.2026.
 *
 * The idea is right and antipattern 27 is real: no addon in this family
 * registers with `Statamic.$dirty`, so half-filled forms vanish on a stray
 * click. What this file proves is that registering from a *hand-built* form is
 * harder than it looks, and gets it wrong in a way that is worse than the
 * problem.
 *
 * **Saving is itself an Inertia visit.** With the flag up, core's `$dirty`
 * challenges that visit — "you have unsaved changes, really leave?" — about
 * the very request that would save them. In a browser the user gets a
 * confirmation dialog when they press Save. Dismissed, the visit is cancelled
 * and **the save silently never happens**, with nothing in the console.
 * Measured: with the guard, `PATCH /leadhub/companies/1` was never sent; with
 * it removed, the same click sent it.
 *
 * Two attempts that did NOT fix it, so nobody repeats them:
 *   1. Clearing in the visit's `onBefore` option — core hooks the router's own
 *      global `before` event, which runs first. The dialog is already up.
 *   2. Clearing synchronously while building the visit options — the dialog
 *      still appeared, so something re-raises the flag between that and the
 *      request.
 *
 * The likely right answer is not this file at all: core tracks dirty state on
 * `PublishContainer`, which takes a `trackDirtyState` prop
 * (`dist-package/types/components/ui/Publish/Container.vue.d.ts:56`) and owns
 * the whole lifecycle. A screen that wants the guard probably wants to be a
 * publish form. Failing that, whoever picks this up should read how
 * `PublishContainer` clears the flag around its own save and copy that
 * ordering exactly — and must prove it with a browser test that asserts the
 * request goes out, because no unit test and no console error catches this.
 *
 * ---
 *
 * Tell the Control Panel that this screen has unsaved work.
 *
 * `Statamic.$dirty` already owns `beforeunload`, the Inertia `before` hook and
 * `popstate` — the whole "you have unsaved changes" apparatus. A page only has
 * to register, and until now no page in this addon family did: a sweep on
 * 03.09.2026 found zero `$dirty` references across twelve addons and 43
 * mutating screens, so a half-filled form vanished on a stray click without a
 * word. (Antipattern 27 in the studio's ui-vocabulary.)
 *
 * Usage — one line per form screen:
 *
 *     const form = ref({ name: '', domain: '' });
 *     const dirty = useDirtyGuard('leadhub.company-form', form);
 *     // …and on a successful save:
 *     dirty.reset();
 *
 * Two properties this has to have, and both are easy to get wrong:
 *
 * 1. **It clears itself when the component goes away.** A flag left standing
 *    prompts on every navigation afterwards, which trains people to click
 *    through the dialog — worse than having no guard at all.
 * 2. **It compares against the values the screen opened with**, not against
 *    "has anything been typed". Typing a character and deleting it again is not
 *    unsaved work, and a guard that thinks it is will cry wolf.
 *
 * @param {string} name Unique per screen. Namespace it with the addon.
 * @param {import('vue').Ref} source The reactive form state to watch.
 * @param {object} [options]
 * @param {() => boolean} [options.when] Extra condition, e.g. a permission.
 */
export function useDirtyGuard(name, source, options = {}) {
    const api = () => (typeof window !== 'undefined' ? window.Statamic?.$dirty : null);

    // The snapshot the screen opened with. JSON is enough: form state here is
    // plain values, and a structural compare beats a deep-equality helper we
    // would have to ship.
    const snapshot = (value) => JSON.stringify(value ?? null);
    let pristine = snapshot(source.value);

    function set(state) {
        api()?.state(name, state);
    }

    function clear() {
        // `remove` rather than `state(name, false)` so the name does not linger
        // in `$dirty.names()` and confuse a later count.
        api()?.remove(name);
    }

    /**
     * After a successful save the current values ARE the saved values, so they
     * become the new baseline. Clearing the flag alone is not enough: the
     * watcher fires again on the next reactive tick, compares against the stale
     * baseline, and puts the flag straight back — which is exactly what a first
     * version of this did, and the browser proof caught it.
     */
    function reset() {
        pristine = snapshot(source.value);
        clear();
    }

    const stop = watch(
        source,
        (value) => {
            if (options.when && ! options.when()) return clear();
            set(snapshot(value) !== pristine);
        },
        { deep: true },
    );

    /**
     * Inertia visit options that survive the guard.
     *
     * Saving is itself an Inertia navigation, so the guard sees it coming and
     * asks "you have unsaved changes, really leave?" — about the very request
     * that saves them. Left alone it does not just annoy: a headless browser
     * dismisses that dialog and the visit is cancelled, so **the save silently
     * never happens**. Found exactly that way, and no console error with it.
     *
     * So: stand down before the request, take the saved values as the new
     * baseline on success, and go back up if the server refused.
     *
     *     router.patch(url, data, dirty.through({ onError: … }))
     */
    function through(options = {}) {
        // Cleared here, synchronously, and NOT in an `onBefore` option: core's
        // `$dirty` hooks into the router's own global `before` event, which
        // runs ahead of per-visit options. From an `onBefore` the dialog has
        // already been raised. Building the options object happens before
        // `router.patch()` is called, so this lands in time.
        clear();

        return {
            ...options,
            onSuccess: (page) => {
                reset();
                return options.onSuccess?.(page);
            },
            onError: (errors) => {
                // Refused: the form still holds unsaved work.
                set(true);
                return options.onError?.(errors);
            },
        };
    }

    onBeforeUnmount(() => {
        stop();
        clear();
    });

    return { clear, reset, set, through };
}
