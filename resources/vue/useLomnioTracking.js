import { onBeforeUnmount, unref, watch } from 'vue';

/**
 * Thin Vue adapter around the plugin-provided window.LomnioTracking SDK.
 *
 * Components can call:
 * const { track } = useLomnioTracking({ unitId: props.unit?.id });
 * track('gallery_browse', { metadata: ['slide=2'] });
 */
export function useLomnioTracking(options = {}) {
	const sdk = () => window.LomnioTracking;

	const unitId = () => (
		typeof options.unitId === 'function'
			? options.unitId()
			: unref(options.unitId)
	);

	const stopContextWatch = watch(unitId, () => {
		sdk()?.setContext({
			unit_id: unitId() ?? null,
		});
	}, { immediate: true });

	onBeforeUnmount(() => {
		stopContextWatch();
		sdk()?.setContext({ unit_id: null });
	});

	return {
		track(eventType, payload = {}) {
			return sdk()?.track(eventType, payload) ?? false;
		},
		setConsent(granted) {
			sdk()?.setConsent(granted);
		},
		flush() {
			return sdk()?.flush() ?? Promise.resolve(false);
		},
		setContext(unitId) {
			sdk()?.setContext({ unit_id: unitId ?? null });
		},
	};
}
