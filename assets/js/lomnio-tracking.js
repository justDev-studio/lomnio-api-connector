(function (window, document) {
	'use strict';

	var config = window.LomnioTrackingConfig || {};
	var endpoint = String(config.endpoint || '');
	var allowedEvents = [
		'page_view',
		'unit_view',
		'floor_plan_view',
		'price_list_view',
		'gallery_browse',
		'contact_form_open',
		'calculator_use',
		'comparison_add',
		'search_filter',
		'time_on_page',
		'download',
		'video_view'
	];
	var immediateEvents = [
		'contact_form_open',
		'calculator_use',
		'comparison_add',
		'download'
	];
	var keys = {
		visitor: 'lomnio_tracking_visitor',
		session: 'lomnio_tracking_session',
		queue: 'lomnio_tracking_queue',
		utm: 'lomnio_tracking_utm'
	};
	var context = {
		unit_id: positiveInteger(config.unitId)
	};
	var consentMode = config.consentMode === 'always' ? 'always' : 'required';
	var consent = consentMode === 'always';
	var queue = [];
	var sending = false;
	var flushTimer = null;
	var pageStartedAt = Date.now();
	var durationRecorded = false;
	var lastUnitViewKey = null;
	var lastPageUrl = cleanUrl(window.location.href);
	var previousUrl = cleanUrl(document.referrer);
	var maxBatch = positiveInteger(config.maxBatch) || 50;
	var batchSize = positiveInteger(config.batchSize) || 10;
	var flushMs = positiveInteger(config.flushMs) || 5000;

	function positiveInteger(value) {
		var parsed = Number(value);
		return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
	}

	function storageGet(storage, key) {
		try {
			return storage.getItem(key);
		} catch (error) {
			return null;
		}
	}

	function storageSet(storage, key, value) {
		try {
			storage.setItem(key, value);
		} catch (error) {
			// Tracking must never break the website when storage is unavailable.
		}
	}

	function storageRemove(storage, key) {
		try {
			storage.removeItem(key);
		} catch (error) {
			// Ignore unavailable storage.
		}
	}

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}

		var bytes = new Uint8Array(16);
		if ( window.crypto && typeof window.crypto.getRandomValues === 'function' ) {
			window.crypto.getRandomValues(bytes);
		} else {
			for ( var index = 0; index < bytes.length; index++ ) {
				bytes[index] = Math.floor(Math.random() * 256);
			}
		}
		bytes[6] = (bytes[6] & 15) | 64;
		bytes[8] = (bytes[8] & 63) | 128;
		var hex = Array.prototype.map.call(bytes, function (byte) {
			return byte.toString(16).padStart(2, '0');
		}).join('');
		return [
			hex.slice(0, 8),
			hex.slice(8, 12),
			hex.slice(12, 16),
			hex.slice(16, 20),
			hex.slice(20)
		].join('-');
	}

	function identity(storage, key) {
		var value = storageGet(storage, key);
		if ( ! value ) {
			value = uuid();
			storageSet(storage, key, value);
		}
		return value;
	}

	function cleanUrl(value) {
		if ( ! value ) {
			return null;
		}
		try {
			var url = new URL(value, window.location.origin);
			return url.origin + url.pathname;
		} catch (error) {
			return null;
		}
	}

	function currentUtm() {
		var existing = storageGet(window.sessionStorage, keys.utm);
		if ( existing ) {
			try {
				return JSON.parse(existing);
			} catch (error) {
				storageRemove(window.sessionStorage, keys.utm);
			}
		}

		var params = new URLSearchParams(window.location.search);
		var names = ['source', 'medium', 'campaign', 'term', 'content'];
		var utm = {};
		names.forEach(function (name) {
			var value = params.get('utm_' + name);
			if ( value ) {
				utm[name] = value.slice(0, 100);
			}
		});

		if ( Object.keys(utm).length ) {
			storageSet(window.sessionStorage, keys.utm, JSON.stringify(utm));
			return utm;
		}
		return null;
	}

	function loadQueue() {
		if ( ! consent ) {
			return [];
		}
		var value = storageGet(window.localStorage, keys.queue);
		if ( ! value ) {
			return [];
		}
		try {
			var parsed = JSON.parse(value);
			return Array.isArray(parsed) ? parsed.slice(-500) : [];
		} catch (error) {
			return [];
		}
	}

	function saveQueue() {
		if ( ! consent || ! queue.length ) {
			storageRemove(window.localStorage, keys.queue);
			return;
		}
		storageSet(window.localStorage, keys.queue, JSON.stringify(queue.slice(-500)));
	}

	function normalizeMetadata(value) {
		if ( ! Array.isArray(value) ) {
			return null;
		}
		return value.filter(function (item) {
			return typeof item === 'string' || typeof item === 'number' || typeof item === 'boolean';
		}).map(String);
	}

	function createEvent(eventType, options) {
		options = options || {};
		var event = {
			visitor_token: identity(window.localStorage, keys.visitor),
			session_id: identity(window.sessionStorage, keys.session),
			event_type: eventType,
			url: cleanUrl(options.url || window.location.href),
			title: String(options.title || document.title || '').slice(0, 255),
			referrer: cleanUrl(options.referrer || previousUrl),
			ts: Number.isInteger(options.ts) ? options.ts : Math.floor(Date.now() / 1000)
		};
		var unitId = positiveInteger(options.unit_id) || context.unit_id;
		var duration = Number(options.duration);
		var metadata = normalizeMetadata(options.metadata);
		var utm = options.utm || currentUtm();

		if ( unitId ) {
			event.unit_id = unitId;
		}
		if ( Number.isFinite(duration) ) {
			event.duration = Math.max(0, Math.min(3600, Math.round(duration)));
		}
		if ( metadata ) {
			event.metadata = metadata;
		}
		if ( utm && typeof utm === 'object' ) {
			event.utm = utm;
		}
		return event;
	}

	function scheduleFlush() {
		if ( flushTimer || ! consent ) {
			return;
		}
		flushTimer = window.setTimeout(function () {
			flushTimer = null;
			flush();
		}, flushMs);
	}

	function track(eventType, options) {
		if ( ! consent || allowedEvents.indexOf(eventType) === -1 ) {
			return false;
		}
		queue.push(createEvent(eventType, options));
		queue = queue.slice(-500);
		saveQueue();

		if ( immediateEvents.indexOf(eventType) !== -1 || queue.length >= batchSize ) {
			flush();
		} else {
			scheduleFlush();
		}
		return true;
	}

	function flush() {
		if ( ! consent || sending || ! endpoint || ! queue.length ) {
			return Promise.resolve(false);
		}

		sending = true;
		var batch = queue.slice(0, maxBatch);

		return window.fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json'
			},
			body: JSON.stringify({ events: batch })
		}).then(function (response) {
			if ( response.ok || (response.status >= 400 && response.status < 500 && response.status !== 429) ) {
				queue.splice(0, batch.length);
				saveQueue();
			}
			return response.ok;
		}).catch(function () {
			return false;
		}).finally(function () {
			sending = false;
			if ( queue.length ) {
				scheduleFlush();
			}
		});
	}

	function beaconFlush() {
		if ( ! consent || ! endpoint || ! queue.length || ! navigator.sendBeacon ) {
			return false;
		}
		var batch = queue.slice(0, maxBatch);
		var body = new Blob(
			[JSON.stringify({ events: batch })],
			{ type: 'application/json' }
		);
		return navigator.sendBeacon(endpoint, body);
	}

	function recordDuration() {
		if ( durationRecorded || ! consent ) {
			return;
		}
		durationRecorded = true;
		track('time_on_page', {
			duration: Math.min(3600, Math.max(0, Math.round((Date.now() - pageStartedAt) / 1000))),
			url: lastPageUrl
		});
	}

	function recordPage() {
		var url = cleanUrl(window.location.href);
		var referrer = pageStartedAt === 0 ? previousUrl : lastPageUrl;
		if ( url === lastPageUrl && ! durationRecorded && pageStartedAt !== 0 ) {
			return;
		}
		previousUrl = referrer;
		lastPageUrl = url;
		pageStartedAt = Date.now();
		durationRecorded = false;
		track('page_view', { url: url, referrer: referrer });
		recordUnitView(url, referrer);
	}

	function recordUnitView(url, referrer) {
		if ( ! consent || ! context.unit_id ) {
			return;
		}
		var key = String(url || cleanUrl(window.location.href)) + ':' + String(context.unit_id);
		if ( key === lastUnitViewKey ) {
			return;
		}
		lastUnitViewKey = key;
		track('unit_view', {
			url: url || cleanUrl(window.location.href),
			referrer: referrer || previousUrl
		});
	}

	function setContext(nextContext) {
		nextContext = nextContext || {};
		context.unit_id = positiveInteger(nextContext.unit_id);
		recordUnitView();
	}

	function clearIdentityAndQueue() {
		queue = [];
		saveQueue();
		storageRemove(window.localStorage, keys.visitor);
		storageRemove(window.sessionStorage, keys.session);
		storageRemove(window.sessionStorage, keys.utm);
	}

	function setConsent(granted) {
		var next = granted === true;
		if ( consent === next ) {
			return;
		}
		consent = next;
		if ( ! consent ) {
			clearIdentityAndQueue();
			lastUnitViewKey = null;
			return;
		}
		queue = loadQueue();
		pageStartedAt = Date.now();
		durationRecorded = false;
		track('page_view');
		recordUnitView();
	}

	function getVisitorToken() {
		return consent ? identity(window.localStorage, keys.visitor) : null;
	}

	function withVisitorToken(fields) {
		var payload = Object.assign({}, fields || {});
		var visitorToken = getVisitorToken();

		if ( visitorToken ) {
			payload.visitor_token = visitorToken;
		} else {
			delete payload.visitor_token;
		}

		return payload;
	}

	function consentEvent(event) {
		var detail = event && event.detail;
		setConsent(detail === true || (detail && detail.granted === true));
	}

	queue = loadQueue();

	window.LomnioTracking = {
		track: track,
		setContext: setContext,
		setConsent: setConsent,
		getVisitorToken: getVisitorToken,
		withVisitorToken: withVisitorToken,
		flush: flush
	};

	window.addEventListener('lomnio:consent', consentEvent);
	document.addEventListener('inertia:navigate', function () {
		recordDuration();
		window.setTimeout(recordPage, 0);
	});
	document.addEventListener('visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			recordDuration();
			beaconFlush();
		}
	});
	window.addEventListener('pagehide', function () {
		recordDuration();
		beaconFlush();
	});

	if ( consent ) {
		pageStartedAt = 0;
		recordPage();
		if ( queue.length ) {
			scheduleFlush();
		}
	}
})(window, document);
