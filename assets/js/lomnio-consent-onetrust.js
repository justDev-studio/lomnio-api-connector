(function (window) {
	'use strict';

	/**
	 * Bridges OneTrust consent to the Lomnio tracking SDK.
	 *
	 * The SDK (consent mode "required") stays silent until it receives a
	 * `lomnio:consent` event. This script grants tracking when any of the
	 * configured OneTrust analytics groups is active, revokes it (the SDK
	 * then wipes the visitor identity and queue) when none is, and re-syncs
	 * on every consent change. If OneTrust never loads, tracking stays off.
	 *
	 * Two groups by default because of how the Strabag OneTrust template is
	 * set up: C0002 (performance) is the taxonomically correct group but has
	 * no cookies assigned on brenner-residence.sk, so the banner never
	 * activates it — the analytics consent people actually give there is
	 * C0007, the same group the Meta Pixel is gated on. C0002 stays in the
	 * list so a later cleanup of the OneTrust template keeps working.
	 */

	var config = window.LomnioTrackingConfig || {};
	var groups = Array.isArray(config.consentGroups) && config.consentGroups.length
		? config.consentGroups.map(String)
		: ['C0002', 'C0007'];

	function granted() {
		var active = ',' + String(window.OnetrustActiveGroups || '') + ',';

		return groups.some(function (group) {
			return active.indexOf(',' + group + ',') !== -1;
		});
	}

	function sync() {
		window.dispatchEvent(new CustomEvent('lomnio:consent', { detail: { granted: granted() } }));
	}

	function arm() {
		if ( ! window.OneTrust || typeof window.OneTrust.OnConsentChanged !== 'function' ) {
			return false;
		}
		window.OneTrust.OnConsentChanged(sync);
		sync();
		return true;
	}

	if ( arm() ) {
		return;
	}

	var attempts = 0;
	var timer = window.setInterval(function () {
		if ( arm() || ++attempts >= 120 ) {
			window.clearInterval(timer);
		}
	}, 500);
})(window);
