import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const trackingSource = await readFile(path.join(pluginRoot, 'assets/js/lomnio-tracking.js'), 'utf8')
const bridgeSource = await readFile(path.join(pluginRoot, 'assets/js/lomnio-consent-onetrust.js'), 'utf8')

const storage = () => {
	const values = new Map()
	return {
		getItem: (key) => values.get(key) ?? null,
		setItem: (key, value) => values.set(key, String(value)),
		removeItem: (key) => values.delete(key),
	}
}

const createHarness = ({ active = ',C0001,', groups, delayed = false } = {}) => {
	const listeners = new Map()
	const intervals = new Map()
	const consentCallbacks = []
	let timerId = 0
	let requests = 0
	const document = {
		title: 'Apartments',
		referrer: '',
		addEventListener() {},
	}
	const window = {
		LomnioTrackingConfig: {
			endpoint: '/wp-json/lomnio/v1/tracking/events',
			consentMode: 'required',
			consentGroups: groups,
		},
		OnetrustActiveGroups: active,
		location: {
			href: 'https://example.test/apartments?utm_source=test',
			origin: 'https://example.test',
			search: '?utm_source=test',
		},
		localStorage: storage(),
		sessionStorage: storage(),
		crypto: { randomUUID: () => '00000000-0000-4000-8000-000000000001' },
		addEventListener(name, callback) {
			const callbacks = listeners.get(name) || []
			callbacks.push(callback)
			listeners.set(name, callbacks)
		},
		dispatchEvent(event) {
			for (const callback of listeners.get(event.type) || []) callback(event)
		},
		setInterval(callback) {
			intervals.set(++timerId, callback)
			return timerId
		},
		clearInterval: (id) => intervals.delete(id),
		setTimeout: () => ++timerId,
		fetch: async () => {
			requests += 1
			return { ok: true, status: 200 }
		},
	}
	const installOneTrust = () => {
		window.OneTrust = { OnConsentChanged: (callback) => consentCallbacks.push(callback) }
	}
	if (!delayed) installOneTrust()
	const context = vm.createContext({
		window,
		document,
		navigator: { sendBeacon: () => { requests += 1; return true } },
		CustomEvent: class {
			constructor(type, options) {
				this.type = type
				this.detail = options.detail
			}
		},
		URL,
		URLSearchParams,
		Blob,
		Uint8Array,
	})
	vm.runInContext(trackingSource, context, { filename: 'lomnio-tracking.js' })
	vm.runInContext(bridgeSource, context, { filename: 'lomnio-consent-onetrust.js' })
	return {
		window,
		intervals,
		consentCallbacks,
		installOneTrust,
		requests: () => requests,
		tick: () => { for (const callback of intervals.values()) callback() },
		change(activeGroups) {
			window.OnetrustActiveGroups = activeGroups
			for (const callback of consentCallbacks) callback()
		},
		queue: () => JSON.parse(window.localStorage.getItem('lomnio_tracking_queue') || '[]'),
	}
}

for (const group of ['C0002', 'C0007']) {
	const harness = createHarness()
	assert.equal(harness.window.LomnioTracking.getVisitorToken(), null)
	assert.deepEqual(harness.queue(), [])
	assert.equal(harness.requests(), 0, 'No tracking request is sent before consent.')
	harness.change(',C0001,' + group + ',')
	assert.ok(harness.window.LomnioTracking.getVisitorToken(), group + ' must grant tracking.')
	assert.deepEqual(harness.queue().map((event) => event.event_type), ['page_view'])
	harness.change(',C0001,' + group + ',')
	assert.equal(harness.queue().length, 1, 'Repeated consent must not duplicate page views.')
	harness.change(',C0001,')
	assert.equal(harness.window.LomnioTracking.getVisitorToken(), null)
	assert.equal(harness.window.localStorage.getItem('lomnio_tracking_visitor'), null)
	for (const key of ['lomnio_tracking_session', 'lomnio_tracking_utm']) {
		assert.equal(harness.window.sessionStorage.getItem(key), null, key + ' is cleared on revocation.')
	}
	assert.deepEqual(harness.queue(), [], 'Revocation must discard queued events.')
	harness.window.LomnioTracking.track('page_view')
	assert.deepEqual(harness.queue(), [], 'Tracking stays off after revocation.')
	harness.change(group)
	assert.equal(harness.queue().length, 1, 'Consent can be granted again without reloading.')
}

const existingConsent = createHarness({ active: 'C0002' })
assert.ok(existingConsent.window.LomnioTracking.getVisitorToken(), 'Existing OneTrust consent is synchronized immediately.')
const exactGroups = createHarness({ active: ',C00020,C00070,' })
assert.equal(exactGroups.window.LomnioTracking.getVisitorToken(), null, 'Group matching must be exact.')
const custom = createHarness({ active: ',C0007,', groups: ['CUSTOM'] })
assert.equal(custom.window.LomnioTracking.getVisitorToken(), null, 'Configured groups replace defaults.')
custom.change(',CUSTOM,')
assert.ok(custom.window.LomnioTracking.getVisitorToken())

const delayed = createHarness({ active: ',C0007,', delayed: true })
assert.equal(delayed.window.LomnioTracking.getVisitorToken(), null, 'Active groups alone must not bypass missing OneTrust.')
delayed.tick()
delayed.installOneTrust()
delayed.tick()
assert.ok(delayed.window.LomnioTracking.getVisitorToken(), 'Late-loading OneTrust is synchronized.')
assert.equal(delayed.intervals.size, 0, 'Polling stops after initialization.')
assert.equal(delayed.consentCallbacks.length, 1, 'Consent callback is registered only once.')

const absent = createHarness({ delayed: true })
for (let attempt = 0; attempt < 120; attempt += 1) absent.tick()
assert.equal(absent.intervals.size, 0, 'Polling is bounded when OneTrust never loads.')
assert.equal(absent.window.LomnioTracking.getVisitorToken(), null)
assert.equal(absent.requests(), 0)

console.log('PASS: OneTrust gates the SDK, handles delayed consent, and clears identity and events on revocation.')
