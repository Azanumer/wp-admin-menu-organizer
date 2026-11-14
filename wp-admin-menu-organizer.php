<?php
/**
 * Plugin Name: Admin Menu Organizer
 * Plugin URI: https://github.com/Azanumer/wp-admin-menu-organizer
 * Description: Reorder, rename and hide WordPress admin menu items per user role — declutter the dashboard for editors, shop managers and clients without touching code.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-admin-menu-organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WAMO_VERSION', '1.0.0' );
define( 'WAMO_OPTION', 'wamo_rules' );

/**
 * Get saved rules, keyed by menu slug.
 *
 * Each rule: array(
 *   'order'      => (int)   sort position, lower first,
 *   'label'      => (string) replacement label, '' = keep original,
 *   'hide_roles' => (array)  role slugs that must not see this item,
 * )
 *
 * @return array
 */
function wamo_get_rules() {
	$rules = get_option( WAMO_OPTION, array() );
	return is_array( $rules ) ? $rules : array();
}

/**
 * Apply the saved rules to the admin menu.
 *
 * Runs late on admin_menu so every plugin has registered its items first.
 * Submenu items are handled separately because they live in $submenu.
 */
function wamo_apply_rules() {
	global $menu, $submenu;

	$rules = wamo_get_rules();
	if ( empty( $rules ) ) {
		return;
	}

	$user = wp_get_current_user();
	if ( ! $user || 0 === (int) $user->ID ) {
		return;
	}
	$user_roles = (array) $user->roles;

	// Administrators always see everything — the settings UI never offers
	// hiding for them, and this guard keeps the `wamo_rules` filter honest.
	$is_admin = in_array( 'administrator', $user_roles, true );

	$rules = apply_filters( 'wamo_rules', $rules );

	// --- Top-level menu: hide, rename, reorder. ---
	$ordered = array();
	foreach ( (array) $menu as $position => $item ) {
		// Separators (empty title, class wp-menu-separator) keep their place.
		if ( empty( $item[0] ) && empty( $item[2] ) ) {
			$ordered[] = array(
				'position' => (float) $position,
				'item'     => $item,
				'sep'      => true,
			);
			continue;
		}
		$slug = isset( $item[2] ) ? $item[2] : '';
		if ( '' === $slug ) {
			continue;
		}
		$rule = isset( $rules[ $slug ] ) ? $rules[ $slug ] : array();

		// Hide for any of the current user's roles.
		$hide_roles = isset( $rule['hide_roles'] ) && is_array( $rule['hide_roles'] ) ? $rule['hide_roles'] : array();
		if ( ! $is_admin && ! empty( array_intersect( $hide_roles, $user_roles ) ) ) {
			continue;
		}

		// Rename.
		if ( ! empty( $rule['label'] ) ) {
			$item[0] = $rule['label'];
		}

		$ordered[] = array(
			'position' => isset( $rule['order'] ) ? (float) $rule['order'] : (float) $position,
			'item'     => $item,
			'sep'      => false,
		);
	}

	// Stable sort by position; ties keep their original relative order.
	usort(
		$ordered,
		function ( $a, $b ) {
			if ( $a['position'] === $b['position'] ) {
				return 0;
			}
			return $a['position'] < $b['position'] ? -1 : 1;
		}
	);

	// Re-key sequentially. WordPress only reads $menu in key order, so
	// gaps are not required — but keep separators at a distinct position.
	$new_menu = array();
	$key      = 1;
	foreach ( $ordered as $entry ) {
		$new_menu[ $key ] = $entry['item'];
		$key++;
	}
	$menu = $new_menu;

	// --- Submenu items: hide only (reordering submenus is rarely wanted
	// and risks breaking capability checks that rely on parent slugs). ---
	foreach ( (array) $submenu as $parent_slug => $items ) {
		foreach ( (array) $items as $index => $sub ) {
			$sub_slug = isset( $sub[2] ) ? $sub[2] : '';
			if ( '' === $sub_slug ) {
				continue;
			}
			// Submenu rules are stored under "parent_slug|sub_slug".
			$rule_key = $parent_slug . '|' . $sub_slug;
			$rule     = isset( $rules[ $rule_key ] ) ? $rules[ $rule_key ] : array();
			$hide_roles = isset( $rule['hide_roles'] ) && is_array( $rule['hide_roles'] ) ? $rule['hide_roles'] : array();
			if ( ! $is_admin && ! empty( array_intersect( $hide_roles, $user_roles ) ) ) {
				unset( $submenu[ $parent_slug ][ $index ] );
				continue;
			}
			if ( ! empty( $rule['label'] ) ) {
				$submenu[ $parent_slug ][ $index ][0] = $rule['label'];
			}
		}
	}
}
add_action( 'admin_menu', 'wamo_apply_rules', 999 );

/**
 * Settings page under Settings → Menu Organizer.
 */
function wamo_register_settings_page() {
	add_options_page(
		__( 'Menu Organizer', 'wp-admin-menu-organizer' ),
		__( 'Menu Organizer', 'wp-admin-menu-organizer' ),
		'manage_options',
		'wp-admin-menu-organizer',
		'wamo_render_settings_page'
	);
}
add_action( 'admin_menu', 'wamo_register_settings_page' );

/**
 * Register settings.
 */
function wamo_register_settings() {
	register_setting( 'wamo_settings_group', WAMO_OPTION, 'wamo_sanitize_rules' );
}
add_action( 'admin_init', 'wamo_register_settings' );

/**
 * Sanitize rules on save.
 *
 * Slugs are validated against the menu items that exist right now, so a
 * hand-edited form post cannot inject arbitrary menu entries.
 *
 * @param array $input Raw input.
 * @return array
 */
function wamo_sanitize_rules( $input ) {
	$out = array();
	if ( ! is_array( $input ) ) {
		return $out;
	}

	global $menu, $submenu;
	$valid_slugs = array();
	foreach ( (array) $menu as $item ) {
		if ( ! empty( $item[2] ) ) {
			$valid_slugs[] = $item[2];
		}
	}
	foreach ( (array) $submenu as $parent_slug => $items ) {
		foreach ( (array) $items as $sub ) {
			if ( ! empty( $sub[2] ) ) {
				$valid_slugs[] = $parent_slug . '|' . $sub[2];
			}
		}
	}

	$all_roles = array_keys( wp_roles()->roles );
	foreach ( $input as $slug => $rule ) {
		$slug = sanitize_key( $slug );
		if ( ! in_array( $slug, $valid_slugs, true ) || ! is_array( $rule ) ) {
			continue;
		}
		$hide_roles = array();
		if ( isset( $rule['hide_roles'] ) && is_array( $rule['hide_roles'] ) ) {
			foreach ( $rule['hide_roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( in_array( $role, $all_roles, true ) ) {
					$hide_roles[] = $role;
				}
			}
		}
		$out[ $slug ] = array(
			'order'      => isset( $rule['order'] ) ? (int) $rule['order'] : 0,
			'label'      => isset( $rule['label'] ) ? sanitize_text_field( $rule['label'] ) : '',
			'hide_roles' => array_values( array_unique( $hide_roles ) ),
		);
	}
	return $out;
}

/**
 * Render the settings page.
 *
 * Lists every top-level item plus its submenus as they exist right now,
 * with order / rename / hide-per-role controls for each.
 */
function wamo_render_settings_page() {
	global $menu, $submenu;
	$rules = wamo_get_rules();
	$roles = wp_roles()->roles;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Menu Organizer', 'wp-admin-menu-organizer' ); ?></h1>
		<p><?php esc_html_e( 'Lower order numbers appear first. Leave a label empty to keep the original. Tick roles to hide an item from them — administrators always see everything.', 'wp-admin-menu-organizer' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'wamo_settings_group' ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Menu item', 'wp-admin-menu-organizer' ); ?></th>
						<th><?php esc_html_e( 'Order', 'wp-admin-menu-organizer' ); ?></th>
						<th><?php esc_html_e( 'Rename to', 'wp-admin-menu-organizer' ); ?></th>
						<th><?php esc_html_e( 'Hide for roles', 'wp-admin-menu-organizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$position = 0;
					foreach ( (array) $menu as $item ) {
						$position++;
						if ( empty( $item[0] ) && empty( $item[2] ) ) {
							continue; // Separator.
						}
						$slug  = $item[2];
						$label = wp_strip_all_tags( $item[0] );
						$rule  = isset( $rules[ $slug ] ) ? $rules[ $slug ] : array();
						wamo_render_rule_row( $slug, $label, $rule, $roles, $position, false );

						// Submenu rows, indented.
						if ( isset( $submenu[ $slug ] ) ) {
							foreach ( (array) $submenu[ $slug ] as $sub ) {
								$sub_slug = $sub[2];
								$sub_key  = $slug . '|' . $sub_slug;
								$sub_rule = isset( $rules[ $sub_key ] ) ? $rules[ $sub_key ] : array();
								wamo_render_rule_row( $sub_key, '— ' . wp_strip_all_tags( $sub[0] ), $sub_rule, $roles, 0, true );
							}
						}
					}
					?>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * One table row of controls for a menu item.
 *
 * @param string $slug     Rule key (top-level slug, or "parent|sub" for submenus).
 * @param string $label    Current display label.
 * @param array  $rule     Saved rule for this item.
 * @param array  $roles    All roles (slug => role data).
 * @param int    $position Current position, used as the default order.
 * @param bool   $is_sub   Whether this is a submenu item (no ordering).
 */
function wamo_render_rule_row( $slug, $label, $rule, $roles, $position, $is_sub ) {
	$field = WAMO_OPTION . '[' . esc_attr( $slug ) . ']';
	$order = isset( $rule['order'] ) ? (int) $rule['order'] : $position;
	$name  = isset( $rule['label'] ) ? $rule['label'] : '';
	$hide  = isset( $rule['hide_roles'] ) && is_array( $rule['hide_roles'] ) ? $rule['hide_roles'] : array();
	?>
	<tr>
		<td><strong><?php echo esc_html( $label ); ?></strong><br /><code><?php echo esc_html( $slug ); ?></code></td>
		<td>
			<?php if ( $is_sub ) : ?>
				<span class="description"><?php esc_html_e( '—', 'wp-admin-menu-organizer' ); ?></span>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>[order]" value="0" />
			<?php else : ?>
				<input type="number" name="<?php echo esc_attr( $field ); ?>[order]" value="<?php echo (int) $order; ?>" min="0" step="1" style="width: 70px;" />
			<?php endif; ?>
		</td>
		<td><input type="text" name="<?php echo esc_attr( $field ); ?>[label]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $label ); ?>" /></td>
		<td>
			<?php foreach ( $roles as $role_slug => $role ) : ?>
				<?php if ( 'administrator' === $role_slug ) { continue; } ?>
				<label style="display: inline-block; margin-right: 10px;">
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[hide_roles][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $hide, true ) ); ?> />
					<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
				</label>
			<?php endforeach; ?>
		</td>
	</tr>
	<?php
}
