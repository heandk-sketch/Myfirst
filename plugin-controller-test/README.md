# Plugin Controller Test

This optional development plugin provides a single **Plugin Controller >
Plugin Tests** page for WordPress administrators when Plugin Controller is
active. Otherwise the page appears under **Tools > Plugin Tests**. The test
plugin is independent of Plugin Controller and does not need to be installed
or activated on a production site.

Expand a plugin to see its test list and latest results, then click **Run
tests**. Results and the time of the last completed run are saved, so they
remain visible after leaving the page. A status is historical, not a live
health check. Plugins without a registered suite are not listed; results for
inactive plugins are retained but hidden until their suite is registered again.

The bundled GolfScore suite is registered when GolfScore is active. It runs
against temporary records and cleans them up. If Plugin Controller is also
active, sign in through its app launcher as a local user with GolfScore access
before running the suite. If the prerequisite is missing, the page displays
an error and preserves the previous results.

## Registering additional suites

Plugins or test adapters can call `pct_register_test_suite()` after plugins
have loaded, guarding the call with `function_exists()` if the test plugin is
optional:

```php
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'pct_register_test_suite' ) ) {
			return;
		}
		pct_register_test_suite(
			array(
				'slug'        => 'my-plugin',
				'title'       => __( 'My Plugin', 'my-plugin' ),
				'description' => __( 'Checks the basic functionality.', 'my-plugin' ),
				'callback'    => 'my_plugin_run_tests',
				'tests'       => array( 'Example check' ),
			)
		);
	},
	20
);
```

The callback must return a non-empty array of results, each with a string
`label`, boolean `passed`, and string `detail`. A `WP_Error` reports a
prerequisite failure without replacing previous results. An exception or an
invalid result is recorded as a failed run. The optional `tests` list contains
unique labels matching the results exactly; it makes unexecuted tests visible.
If a run omits a listed check, its status is **Incomplete**, unless a check
failed. Each suite is responsible for isolating and cleaning up its test data.
Tests run only after an administrator action protected by a nonce.
