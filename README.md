# Lomnio API Connector

WordPress plugin for syncing Lomnio data into local database tables and reading it from theme code.

## Installation

Install Composer dependencies inside the plugin directory:

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
- run all syncs manually;
- run Project or Units sync separately;
- check Status, Message, and Fetched At.

Hidden API token settings page:

```text
/wp-admin/admin.php?page=lomnio-api-connector
```

The page is available only to users with `manage_options`. The API token is stored encrypted in `wp_options`.

You can paste either a raw token or the full header:

```text
Authorization: Bearer YOUR_API_TOKEN
```

After saving a valid token, the plugin queues immediate Project and Units sync when the endpoints are active.

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

Default schedules:

- Project: once per day;
- Units: every 10 minutes.

Available schedules include 5 minutes, 10 minutes, 30 minutes, hourly, twice daily, and daily.

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

Each unit row stores the detailed `UnitResource` in `list_payload_json` and `detail_payload_json`, plus separate columns for filtering and sorting.

## Theme Usage

Project as object:

```php
use LomnioApiConnector\Database\ProjectRepository;

$lomnio_project = new ProjectRepository();

$section['project'] = $lomnio_project->get_project_object();
```

Project as array:

```php
use LomnioApiConnector\Database\ProjectRepository;

$lomnio_project = new ProjectRepository();

$project = $lomnio_project->get_api_response();
```

Units list:

```php
use LomnioApiConnector\Database\UnitRepository;

$lomnio_units = new UnitRepository();

$section['units'] = $lomnio_units->get_units();
```

Units with filters:

```php
use LomnioApiConnector\Database\UnitRepository;

$lomnio_units = new UnitRepository();

$section['units'] = $lomnio_units->get_units(
	array(
		'status' => 'available',
		'floor'  => 2,
	)
);
```

Single unit by ID:

```php
use LomnioApiConnector\Database\UnitRepository;

$lomnio_units = new UnitRepository();

$section['unit'] = $lomnio_units->get_unit_by_id( 51 );
```

Single unit by code:

```php
use LomnioApiConnector\Database\UnitRepository;

$lomnio_units = new UnitRepository();

$section['unit'] = $lomnio_units->get_unit_by_code( 'A101' );
```

## Available Unit Filters

`get_units()` supports these filters:

- `id`
- `unit_id`
- `code`
- `status`
- `status_code`
- `type`
- `layout_type`
- `floor`
- `floor_number`
- `floor_id`
- `building_id`
- `phase_id`
- `room_count`

Filter values can be scalar values or arrays:

```php
$units = $lomnio_units->get_units(
	array(
		'status' => array( 'available', 'reserved' ),
		'floor'  => array( 1, 2, 3 ),
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
