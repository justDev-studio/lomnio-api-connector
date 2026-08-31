import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const trackingSource = await readFile(path.join(pluginRoot, 'assets/js/lomnio-tracking.js'), 'utf8')

class FakeStorage {
	#values = new Map()

	getItem(key) {
		return this.#values.has(key) ? this.#values.get(key) : null
	}

	setItem(key, value) {
		this.#values.set(key, String(value))
	}

	removeItem(key) {
		this.#values.delete(key)
	}
}

const createHarness = () => {
	const documentListeners = new Map()
	let timeoutId = 0

	const document = {
		title: 'Apartments',
		referrer: '',
		visibilityState: 'visible',
		addEventListener(name, listener) {
			documentListeners.set(name, listener)
		},
	}

	const window = {
		LomnioTrackingConfig: {
			endpoint: '/wp-json/lomnio/v1/tracking/events',
			consentMode: 'always',
			batchSize: 50,
			flushMs: 5000,
		},
		location: {
			href: 'https://example.test/apartments?utm_source=test',
			origin: 'https://example.test',
			search: '?utm_source=test',
		},
		localStorage: new FakeStorage(),
		sessionStorage: new FakeStorage(),
		crypto: {
			randomUUID: () => '00000000-0000-4000-8000-000000000001',
		},
		addEventListener() {},
		setTimeout(callback, delay) {
			timeoutId += 1
			if (delay === 0) {
				callback()
			}
			return timeoutId
		},
		fetch: async () => ({ ok: true, status: 200 }),
	}

	vm.runInNewContext(
		trackingSource,
		{
			window,
			document,
			navigator: { sendBeacon: () => true },
			URL,
			URLSearchParams,
			Blob,
			Uint8Array,
		},
		{ filename: 'lomnio-tracking.js' }
	)

	return {
		window,
		documentListeners,
		queue: () => JSON.parse(window.localStorage.getItem('lomnio_tracking_queue') || '[]'),
	}
}

const initialHarness = createHarness()

assert.deepEqual(
	initialHarness.queue().map((event) => event.event_type),
	['page_view'],
	'Initial tracking setup must record exactly one page view.'
)

initialHarness.documentListeners.get('inertia:navigate')()

assert.deepEqual(
	initialHarness.queue().map((event) => event.event_type),
	['page_view'],
	'Initial Inertia navigation must not duplicate the page view or add a zero-second duration.'
)

initialHarness.window.location.href = 'https://example.test/apartments/next'
initialHarness.window.location.search = ''
initialHarness.documentListeners.get('inertia:navigate')()

assert.deepEqual(
	initialHarness.queue().map((event) => event.event_type),
	['page_view', 'time_on_page', 'page_view'],
	'A subsequent Inertia navigation must record duration and the destination page.'
)
assert.equal(initialHarness.queue()[2].url, 'https://example.test/apartments/next', 'The destination page URL must be recorded.')

const immediateNavigationHarness = createHarness()
immediateNavigationHarness.window.location.href = 'https://example.test/apartments/direct'
immediateNavigationHarness.window.location.search = ''
immediateNavigationHarness.documentListeners.get('inertia:navigate')()

assert.deepEqual(
	immediateNavigationHarness.queue().map((event) => event.event_type),
	['page_view', 'time_on_page', 'page_view'],
	'The first Inertia event must still be handled when it is a real navigation to another URL.'
)

console.log('PASS: Inertia tracking ignores only the duplicate initial navigation event.')
