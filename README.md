# Lomnio API Connector

WordPress plugin for syncing Lomnio data into local database tables and reading it from theme code.

## Installation

Recommended project install:

```bash
composer require justdev/lomnio-api-connector
```

When the plugin is installed from the project Composer file, Composer installs the plugin into `wp-content/plugins/lomnio-api-connector` through `composer/installers`. Plugin dependencies are installed by the root project.

Keep `woocommerce/action-scheduler` in root `vendor`, not in `wp-content/plugins/action-scheduler`. Add this exception before the generic `type:wordpress-plugin` installer path in the root project:

```json
"installer-paths": {
	"vendor/woocommerce/action-scheduler/": [
		"woocommerce/action-scheduler"
	],
	"wp-content/plugins/{$name}/": [
		"type:wordpress-plugin"
	]
}
```

Local plugin development install:

```bash
cd wp-content/plugins/lomnio-api-connector
composer install
composer dump-autoload --no-dev
```

Activate the plugin in WordPress admin:

```text
/wp-admin/plugins.php
```

## Admin Pages

Endpoint management page:

```text
/wp-admin/admin.php?page=lomnio-api-endpoints
```

This page allows admins to:

- enable or disable endpoints;
- set sync frequency;
- configure which `WP_ENV` values may send Leads;
- run all syncs manually;
- run Project, Units, or Floors sync separately;
- check Status, Message, and Fetched / Used At.

Frontend page display settings:

```text
/wp-admin/admin.php?page=lomnio-api-pages
```

This page controls the universal Lomnio frontend layer:

- `Enable Lomnio pages` turns the whole layer on or off;
- floor and unit route slugs;
- phase-aware routes;
- theme templates used for floor and unit pages;
- Inertia component names;
- Floor Page and Unit Page settings custom post types;
- flats home page used by breadcrumbs;
- ACF cache TTL.

When `Enable Lomnio pages` is disabled, the plugin does not register rewrite rules, does not register route query vars, and does not route requests to theme templates.

Settings content is managed through two custom post types created by the plugin:

- `floor_settings`
- `single_settings`

Each post type allows one settings page per language. WPML translations are supported and should be used for localized content.

Theme templates should use the global facade:

```php
$settings = \LomnioPages::settings();

$floor_slug = $settings['floor_slug'];
$unit_slug = $settings['unit_slug'];
$phase_query_var = $settings['phase_query_var'];

$floor = \LomnioPages::floor_number();
$unit_code = \LomnioPages::unit_id();
$phase = \LomnioPages::phase();

$floor_base_url = \LomnioPages::floor_base();
$phase_floor_base_url = \LomnioPages::floor_base( $phase );
$floor_url = \LomnioPages::floor_link( null, $floor );
$unit_url = \LomnioPages::unit_link( $unit_code );

$floor_fields = \LomnioPages::fields( 'floor' );
$unit_fields = \LomnioPages::fields( 'unit' );

$floor_component = \LomnioPages::component( 'floor' );
$unit_component = \LomnioPages::component( 'unit' );
$not_found_component = \LomnioPages::not_found_component();
```

Hidden API token settings page:

```text
/wp-admin/admin.php?page=lomnio-api-connector
```

The page is available only to users with `manage_options`. The API token is stored encrypted in `wp_options`.

You can paste either a raw token or the full header:

```text
Authorization: Bearer YOUR_API_TOKEN
```

After saving a valid token, the plugin queues immediate Project, Units, and Floors sync when the endpoints are active.

## Sync

The plugin uses Action Scheduler.

Project action hook:

```text
lomnio_api_connector_sync_project
```

Units action hook:

```text
lomnio_api_connector_sync_units
```

Floors action hook:

```text
lomnio_api_connector_sync_floors
```

Default schedules:

- Project: once per day;
- Units: every 10 minutes.
- Floors: every 10 minutes.

Available schedules include 5 minutes, 10 minutes, 30 minutes, hourly, twice daily, and daily.

## External Sync Webhook

The standard WordPress REST API webhook receives external Lomnio data:

```text
POST https://site.com/wp-json/lomnio/v1/webhook
```

Configure the Lomnio project signing secret on the plugin settings page. Every request must include `X-Lomnio-Signature: sha256=...`; the plugin verifies the HMAC-SHA256 signature over the raw body and returns HTTP `401` when it is invalid. Delivery IDs from `X-Lomnio-Delivery` are deduplicated for seven days.

`webhook.test` validates the integration without changing data. Unit events upsert the complete `unit` snapshot. When `deleted` is true or `visible` is false, the local unit is removed. No full pull synchronization is triggered.

For temporary integration debugging, the most recently received request is shown to administrators on the Lomnio API Endpoints page. The view includes headers, raw body, JSON, form, query, and file parameters and can be cleared from the same page. Raw bodies larger than 1 MB are truncated.

## Leads

Leads are sent through:

```text
/v1/leads
```

The endpoint is managed on the endpoint management page. If Leads is inactive, calls to the sender return a skipped result and do not send, note, or log anything.

Allowed environments are controlled per endpoint. By default, only `production` sends real API requests. If the current `WP_ENV` is not allowed, the payload is recorded locally instead of being sent. The `All environments` option allows real sending from every environment.

Use the global class when you do not want to import a namespace:

```php
$result = \LomnioLeads::send(
	array(
		'name'    => sanitize_text_field( $_POST['input_10'] ?? '' ),
		'email'   => sanitize_email( $_POST['input_3'] ?? '' ),
		'phone'   => sanitize_text_field( $_POST['input_4'] ?? '' ),
		'message' => sanitize_textarea_field( $_POST['input_5'] ?? '' ),
	),
	array(
		'source'   => 'Contact form',
		'entry_id' => isset( $entry['id'] ) ? (int) $entry['id'] : 0,
	)
);
```

When `GFAPI` exists and `entry_id` is passed, the plugin writes the request result to the Gravity Forms entry notes. For other forms, the plugin writes to:

```text
wp-content/uploads/lomnio_leads_log.txt
```

The sender returns either a result array or `WP_Error`.

## Stored Data

Project data is stored in:

```text
{$wpdb->prefix}lomnio_project
```

The table stores `ProjectResource` without the API `data` wrapper.

Units data is stored in:

```text
{$wpdb->prefix}lomnio_units
```

Units sync uses one detailed list request:

```text
/v1/units?per_page=-1&detailed=true
```

The plugin does not call:

```text
/v1/units/{unit}
```

Each unit row stores the full `UnitDetailResource` in `payload_json`, plus separate columns for filtering and sorting: `project_id`, identifiers, status, type, layout, orientation, building, floor, phase, areas, pricing, floor plan, and bundle fields.

Legacy unit columns such as `list_payload_json`, `detail_payload_json`, `list_hash`, and `detail_hash` are not used. If `lomnio_units` was created by an older plugin version, recreate or migrate the table before running the current sync.

Floors data is stored in:

```text
{$wpdb->prefix}lomnio_floors
```

Floors sync uses:

```text
/v1/floors
```

Each floor row stores the full `FloorResource` in `payload_json`, plus separate columns for filtering and sorting: `project_id`, `id`, `name`, `number`, `sort_order`, `building`, `availability`, unit counts, `facade_map_id`, and `floor_plan_url`.

Local table relations:

```text
lomnio_project.project_id = lomnio_units.project_id
lomnio_project.project_id = lomnio_floors.project_id
lomnio_floors.floor_id    = lomnio_units.floor_id
```

These are indexed local relation columns, not database-level foreign key constraints.

## Theme Usage

Use global Lomnio classes in theme code. Do not instantiate repository classes directly unless you need low-level plugin internals.

Frontend routing and display helpers:

```php
$settings = \LomnioPages::settings();

$floor_slug = $settings['floor_slug'];
$unit_slug = $settings['unit_slug'];
$phase_query_var = $settings['phase_query_var'];

$section['floor_link'] = \LomnioPages::floor_base();
$section['phase_floor_link'] = \LomnioPages::floor_base( \LomnioPages::phase() );
```

Project as object:

```php
$section['project'] = \LomnioProject::get();
```

Project as array:

```php
$project = \LomnioProject::to_array();
```

Project ID:

```php
$project_id = \LomnioProject::id();
```

Units list:

```php
$section['units'] = \LomnioUnits::get();
```

Each unit object contains `url` built from the unit `code` and without a phase/project segment.

Units with filters:

```php
$section['units'] = \LomnioUnits::get(
	array(
		'status' => 'available',
		'floor'  => 2,
	)
);
```

Single unit by ID:

```php
$section['unit'] = \LomnioUnits::find( 51 );
```

Single unit by code:

```php
$section['unit'] = \LomnioUnits::find_by_code( 'A101' );
```

Units by floor:

```php
$section['units'] = \LomnioUnits::by_floor( 10 );
```

Floors list:

```php
$section['floors'] = \LomnioFloors::get();
```

Available floors contain `url` built only from the floor number. Unavailable floors return `url => null`.

Floors with filters:

```php
$section['floors'] = \LomnioFloors::get(
	array(
		'building_id'  => 1,
		'availability' => 'available',
	)
);
```

Single floor by ID:

```php
$section['floor'] = \LomnioFloors::find( 10 );
```

Single floor by number:

```php
$section['floor'] = \LomnioFloors::find_by_number( 2 );
```

Floors by project:

```php
$section['floors'] = \LomnioFloors::by_project( 51 );
```

## Available Unit Filters

`\LomnioUnits::get()` supports these filters:

- `id`
- `project_id`
- `unit_id`
- `code`
- `status`
- `status_code`
- `status_label`
- `status_color`
- `status_is_system`
- `type`
- `layout_type`
- `orientation`
- `floor`
- `floor_number`
- `floor_id`
- `floor_name`
- `building_id`
- `building_name`
- `phase_id`
- `phase_name`
- `room_count`
- `area`
- `area_floor`
- `area_gross`
- `area_building`
- `area_land`
- `price_without_vat`
- `price_with_vat`
- `discount_without_vat`
- `discount_with_vat`
- `discounted_price_without_vat`
- `discounted_price_with_vat`
- `price_per_sqm`
- `vat_rate`
- `pricing_hidden`
- `pricing_display_text`
- `floor_plan_url`
- `bundle_id`
- `bundle_name`

Filter values can be scalar values or arrays:

```php
$units = \LomnioUnits::get(
	array(
		'status' => array( 'available', 'reserved' ),
		'floor'  => array( 1, 2, 3 ),
	)
);
```

## Available Floor Filters

`\LomnioFloors::get()` supports these filters:

- `id`
- `project_id`
- `floor_id`
- `name`
- `number`
- `floor_number`
- `sort_order`
- `building_id`
- `building_name`
- `availability`
- `status`
- `units_count`
- `available_units_count`
- `facade_map_id`
- `floor_plan_url`

Filter values can be scalar values or arrays:

```php
$floors = \LomnioFloors::get(
	array(
		'building_id'  => array( 1, 2 ),
		'availability' => array( 'available', 'unavailable' ),
	)
);
```

## Direct Authorization Header

If plugin code needs the configured authorization header:

```php
$headers = \LomnioApiConnector\Plugin::instance()
	->secret_storage()
	->get_authorization_headers();
```

The result is either a headers array or `WP_Error`.
