# Plugin Controller

Plugin Controller tilbyder en fælles admin-menu, hvor andre plugins frivilligt kan registrere deres administrationssider og indstillinger. Eksisterende plugins ændres ikke automatisk.

## Egen admin-side

Kald API'et efter Plugin Controller er indlæst, eksempelvis fra et `plugins_loaded`-callback med en senere prioritet:

```php
add_action(
	'plugins_loaded',
	static function () {
		pc_register_admin_page(
			array(
				'slug'       => 'my-plugin-tools',
				'title'      => __( 'My Plugin', 'my-plugin' ),
				'capability' => 'manage_options',
				'callback'   => 'my_plugin_render_admin_page',
			)
		);
	},
	20
);
```

## Indstillingsside

Indstillingssider bruger WordPress Settings API. Den oprettede option gemmes som en array under `option_name`. Felttyperne `text`, `textarea`, `checkbox` og `select` valideres og escapes af controlleren:

```php
add_action(
	'plugins_loaded',
	static function () {
		pc_register_settings_page(
			array(
				'slug'        => 'my-plugin-settings',
				'title'       => __( 'My Plugin Settings', 'my-plugin' ),
				'option_name' => 'my_plugin_settings',
				'fields'      => array(
					array(
						'id'    => 'welcome_text',
						'label' => __( 'Welcome text', 'my-plugin' ),
						'type'  => 'text',
					),
					array(
						'id'      => 'layout',
						'label'   => __( 'Layout', 'my-plugin' ),
						'type'    => 'select',
						'options' => array(
							'compact' => __( 'Compact', 'my-plugin' ),
							'wide'    => __( 'Wide', 'my-plugin' ),
						),
					),
				),
			)
		);
	},
	20
);
```

API-funktionerne returnerer `true` ved registrering og `false` med en WordPress-doing-it-wrong-notice ved ugyldige eller duplikerede argumenter. Siderne ligger under **Plugin Controller** og bruger som standard `manage_options`. Den fælles topmenu er administratorbegrænset, så registrerede sider er kun tilgængelige for brugere, der også kan tilgå denne menu.

Plugin Controller er ikke en dependency, før et plugin selv kalder API'et. Tjek `function_exists( 'pc_register_admin_page' )` eller `function_exists( 'pc_register_settings_page' )`, hvis integrationen også skal fungere uden Plugin Controller aktivt.

## Fælles lokal adgang

Plugin Controller har en **Local Access**-side, hvor administratorer kan konfigurere op til fire WordPress-brugere med separate lokale adgangskoder. Adgangskoder gemmes som WordPress-hashes; vellykket login opretter en underskrevet, HTTP-only cookie, som udløber efter 14 dage.

Hver bruger kan tildeles adgang til bestemte plugins under **Plugin access**. Plugins registrerer sig med `pc_register_access_plugin( 'slug', 'Titel' )` (fx fra `plugins_loaded` med prioritet 20) og tjekker adgang med `pc_local_user_can_access( 'slug' )`. Brugere har som standard ikke adgang til nogen plugins, før en administrator har valgt dem.

Frontend-plugins kan bruge `pc_get_local_access_user_id()` (returnerer `0`, hvis der ikke er et gyldigt lokalt login) og `pc_render_local_access_form()` til loginformularen. Formularen håndterer selv login og logout via nonce-beskyttede POST-forespørgsler. Shortcode-beskyttede data bør kun vises, når bruger-ID'et er større end nul.

## Installerbare apps (PWA)

Plugins, der skal kunne installeres som app på en telefon, registrerer sig med `pc_register_pwa()` (fx fra `plugins_loaded` med prioritet 20):

```php
pc_register_pwa(
	array(
		'slug'        => 'min-app',
		'name'        => 'Min App',
		'short_name'  => 'App',
		'shortcode'   => 'min_app_shortcode',
		'theme_color' => '#1a7f37',
		'icon_192'    => MIN_APP_URL . 'icon-192.png',
		'icon_512'    => MIN_APP_URL . 'icon-512.png',
	)
);
```

Hver side udskriver kun ét manifest og én service worker: den for den app, hvis shortcode ligger på siden. Andre PWA-plugins' hooks fjernes på siden (filteret `pc_pwa_conflicting_hooks` styrer listen; GolfScore er med som standard). Det forhindrer, at en app åbner en anden.
