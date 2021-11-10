<?php
/**
 * ------------------------------------------------------------------------------
 * Plugin Name: Redirect
 * Description: Redirect URIs with a 301 (permanent) or 302 (temporary) redirect.
 * Version: 1.0.0
 * Author: azurecurve
 * Author URI: https://development.azurecurve.co.uk/classicpress-plugins/
 * Plugin URI: https://development.azurecurve.co.uk/classicpress-plugins/redirect/
 * Text Domain: redirect
 * Domain Path: /languages
 * ------------------------------------------------------------------------------
 * This is free software released under the terms of the General Public License,
 * version 2, or later. It is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Full
 * text of the license is available at https://www.gnu.org/licenses/rrl-2.0.html.
 * ------------------------------------------------------------------------------
 */

// Declare the namespace.
namespace azurecurve\Redirect;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// include plugin menu
require_once dirname( __FILE__ ) . '/pluginmenu/menu.php';
add_action( 'admin_init', 'azrcrv_create_plugin_menu_r' );

// include update client
require_once dirname( __FILE__ ) . '/libraries/updateclient/UpdateClient.class.php';

/**
 * Setup registration activation hook, actions, filters and shortcodes.
 *
 * @since 1.0.0
 */

// constants
const DB_VERSION     = '1.0.0';
const DATABASE_TABLE = 'azrcrv_redirects';

// register activation hooks
register_activation_hook( __FILE__, __NAMESPACE__ . '\\install' );

// add actions
add_action( 'plugins_loaded', __NAMESPACE__ . '\\install' );
add_action( 'admin_menu', __NAMESPACE__ . '\\create_admin_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\\register_admin_styles' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_styles' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_languages' );
add_action( 'admin_post_azrcrv_r_save_options', __NAMESPACE__ . '\\save_options' );
add_action( 'admin_post_azrcrv_r_add_redirect', __NAMESPACE__ . '\\add_redirect' );
add_action( 'admin_post_azrcrv_r_manage_redirects', __NAMESPACE__ . '\\manage_redirects' );
add_action( 'init', __NAMESPACE__ . '\\redirect_incoming' );
add_action( 'pre_post_update', __NAMESPACE__ . '\\pre_post_update', 10, 2 );
add_action( 'post_updated', __NAMESPACE__ . '\\add_redirect_for_changed_permalink', 11, 3 );

// add filters
add_filter( 'plugin_action_links', __NAMESPACE__ . '\\add_plugin_action_link', 10, 2 );
add_filter( 'codepotent_update_manager_image_path', __NAMESPACE__ . '\\custom_image_path' );
add_filter( 'codepotent_update_manager_image_url', __NAMESPACE__ . '\\custom_image_url' );

/**
 * Create table on install of plugin.
 *
 * @since 1.0.0
 */
function install() {
	global $wpdb;

	$installed_ver = get_option( 'azrcrv-r-db-version' );

	if ( $installed_ver != DB_VERSION ) {

		$table_name = $wpdb->prefix . 'azrcrv_redirects';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_url MEDIUMTEXT NOT NULL,
			redirect_count INT(10) UNSIGNED NOT NULL DEFAULT '0',
			last_redirect DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_redirect_utc DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			status ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
			redirect_type INT(11) UNSIGNED NOT NULL,
			destination_url MEDIUMTEXT NOT NULL,
			added DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			added_utc DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id) USING BTREE,
			INDEX source_url (source_url (191)) USING BTREE,
			INDEX destination_url (source_url (191)) USING BTREE,
			INDEX status (status) USING BTREE
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'azrcrv-r-db-version', DB_VERSION );
	}
}

/**
 * Register admin styles.
 *
 * @since 1.0.0
 */
function register_admin_styles() {
	wp_register_style( 'azrcrv-r-admin-styles', plugins_url( 'assets/css/admin.css', __FILE__ ) );
}

/**
 * Enqueue admin styles.
 *
 * @since 1.0.0
 */
function enqueue_admin_styles() {
	if ( isset( $_GET['page'] ) and ( $_GET['page'] == 'azrcrv-r' or $_GET['page'] == 'azrcrv-r-ar' or $_GET['page'] == 'azrcrv-r-mr' ) ) {
		wp_enqueue_style( 'azrcrv-r-admin-styles' );
	}
}

/**
 * Load language files.
 *
 * @since 1.0.0
 */
function load_languages() {
	$plugin_rel_path = basename( dirname( __FILE__ ) ) . '/languages';
	load_plugin_textdomain( 'azrcrv-r', false, $plugin_rel_path );
}

/**
 * Get options including defaults.
 *
 * @since 1.0.0
 */
function get_option_with_defaults( $option_name ) {

	$defaults = array(
		'default-redirect'           => 301, // permanent = 301
		'redirect-permalink-changes' => 0,
		'redirect-rows'              => 20,
	);

	$options = get_option( $option_name, $defaults );

	$options = recursive_parse_args( $options, $defaults );

	return $options;

}

/**
 * Recursively parse options to merge with defaults.
 *
 * @since 1.0.0
 */
function recursive_parse_args( $args, $defaults ) {
	$new_args = (array) $defaults;

	foreach ( $args as $key => $value ) {
		if ( is_array( $value ) && isset( $new_args[ $key ] ) ) {
			$new_args[ $key ] = recursive_parse_args( $value, $new_args[ $key ] );
		} else {
			$new_args[ $key ] = $value;
		}
	}

	return $new_args;
}

/**
 * Add action link on plugins page.
 *
 * @since 1.0.0
 */
function add_plugin_action_link( $links, $file ) {
	static $this_plugin;

	if ( ! $this_plugin ) {
		$this_plugin = plugin_basename( __FILE__ );
	}

	if ( $file == $this_plugin ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=azrcrv-r' ) . '"><img src="' . plugins_url( '/pluginmenu/images/logo.svg', __FILE__ ) . '" style="padding-top: 2px; margin-right: -5px; height: 16px; width: 16px;" alt="azurecurve" />' . esc_html__( 'Settings', 'redirect' ) . '</a>';
		array_unshift( $links, $settings_link );
	}

	return $links;
}

/**
 * Custom plugin image path.
 *
 * @since 1.0.0
 */
function custom_image_path( $path ) {
	if ( strpos( $path, 'azrcrv-redirect' ) !== false ) {
		$path = plugin_dir_path( __FILE__ ) . 'assets/pluginimages';
	}
	return $path;
}

/**
 * Custom plugin image url.
 *
 * @since 1.0.0
 */
function custom_image_url( $url ) {
	if ( strpos( $url, 'azrcrv-redirect' ) !== false ) {
		$url = plugin_dir_url( __FILE__ ) . 'assets/pluginimages';
	}
	return $url;
}

/**
 * Add to menu.
 *
 * @since 1.0.0
 */
function create_admin_menu() {

	add_menu_page(
		esc_html__( 'Redirect', 'redirect' ),
		esc_html__( 'Redirect', 'redirect' ),
		'manage_options',
		'azrcrv-r',
		__NAMESPACE__ . '\\display_options',
		'dashicons-migrate',
		50
	);

	add_submenu_page(
		'azrcrv-r',
		esc_html__( 'Redirect Settings', 'redirect' ),
		esc_html__( 'Settings', 'redirect' ),
		'manage_options',
		'azrcrv-r',
		__NAMESPACE__ . '\\display_options'
	);

	add_submenu_page(
		'azrcrv-r',
		esc_html__( 'Manage Redirects', 'redirect' ),
		esc_html__( 'Manage Redirects', 'redirect' ),
		'manage_options',
		'azrcrv-r-mr',
		__NAMESPACE__ . '\\display_manage_redirects'
	);

	add_submenu_page(
		'azrcrv-plugin-menu',
		esc_html__( 'Redirect Settings', 'redirect' ),
		esc_html__( 'Redirect', 'redirect' ),
		'manage_options',
		'azrcrv-r',
		__NAMESPACE__ . '\\display_options'
	);

}

/**
 * Load admin css.
 *
 * @since 1.0.0
 */
function load_admin_style() {
	wp_register_style( 'r-css', plugins_url( 'assets/css/admin.css', __FILE__ ), false, '1.0.0' );
	wp_enqueue_style( 'r-css' );
}

/**
 * Display Settings page.
 *
 * @since 1.0.0
 */
function display_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'redirect' ) );
	}

	global $wpdb;

	// Retrieve plugin configuration options from database
	$options = get_option_with_defaults( 'azrcrv-r' );

	echo '<div id="azrcrv-r-general" class="wrap">';

	?>
		<h1>
			<?php
				echo '<a href="https://development.azurecurve.co.uk/classicpress-plugins/"><img src="' . plugins_url( '/pluginmenu/images/logo.svg', __FILE__ ) . '" style="padding-right: 6px; height: 20px; width: 20px;" alt="azurecurve" /></a>';
				esc_html_e( get_admin_page_title() );
			?>
		</h1>
		<?php

		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible">
					<p><strong>' . __( 'Settings have been saved.', 'redirect' ) . '</strong></p>
				</div>';
		}

		if ( $options['default-redirect'] == 301 ) {
			$redirect_301 = 'selected=selected';
			$redirect_302 = '';
		} else {
			$redirect_301 = '';
			$redirect_302 = 'selected=selected';
		}
		$tab_1 = '<table class="form-table">
		
					<tr>
						<th scope="row">
							
								' . esc_html__( 'Default Redirect Type', 'redirect' ) . '
							
						</th>
					
						<td>
							
							<select name="default-redirect">
								<option value="301" ' . $redirect_301 . '>301 - Permanent Redirect</option>
								<option value="302" ' . $redirect_302 . '>302 - Temporary Redirect</option>
							</select>
							<p class="description">' . esc_html__( 'Type of redirect to use as default.', 'redirect' ) . '</p>
						
						</td>
	
					</tr>
		
					<tr>
						<th scope="row">
							
								' . esc_html__( 'Redirect Permalink Changes', 'redirect' ) . '
							
						</th>
					
						<td>
							
							<input name="redirect-permalink-changes" type="checkbox" id="redirect-permalink-changes" value="1" ' . checked( '1', $options['redirect-permalink-changes'], false ) . ' />
							<label for="redirect-permalink-changes"><span class="description">
								' . esc_html__( 'Monitor for permalink changes and add redirect.', 'smtp' ) . '
							</span></label>
							
						</td>
	
					</tr>
		
					<tr>
						<th scope="row">
							
								' . esc_html__( 'Redirect Rows', 'redirect' ) . '
							
						</th>
					
						<td>
							
							<input name="redirect-rows" type="number" step="1" min="1" id="redirect-rows" value="' . stripslashes( $options['redirect-rows'] ) . '" class="small-text" />
							<label for="redirect-rows"><span class="description">
								' . esc_html__( 'Number of rows to show in Manage Redirects list.', 'smtp' ) . '
							</span></label>
							
						</td>
	
					</tr>
					
				</table>';

		?>
		<form method="post" action="admin-post.php">
			<fieldset>
						
				<input type="hidden" name="action" value="azrcrv_r_save_options" />
				<input name="page_options" type="hidden" value="default-redirect,redirect-permalink-changes" />
				
				<?php
					// <!-- Adding security through hidden referrer field -->
					wp_nonce_field( 'azrcrv-r', 'azrcrv-r-nonce' );
				?>
				
				<div id="tabs">
					<div id="tab-panel-1" >
						<?php echo $tab_1; ?>
					</div>
				</div>
			</fieldset>
			
			<input type="submit" name="btn_save" value="<?php esc_html_e( 'Save Settings', 'redirect' ); ?>" class="button-primary"/>
		</form>
	</div>
	<?php

}

/**
 * Save settings.
 *
 * @since 1.0.0
 */
function save_options() {
	// Check that user has proper security level
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permissions to perform this action', 'redirect' ) );
	}

	// Check that nonce field created in configuration form is present
	if ( ! empty( $_POST ) && check_admin_referer( 'azrcrv-r', 'azrcrv-r-nonce' ) ) {

		// Retrieve original plugin options array
		$options = get_option( 'azrcrv-r' );

		// update settings
		$option_name             = 'default-redirect';
		$options[ $option_name ] = (int) $_POST[ $option_name ];

		$option_name = 'redirect-permalink-changes';
		if ( isset( $_POST[ $option_name ] ) ) {
			$options[ $option_name ] = 1;
		} else {
			$options[ $option_name ] = 0;
		}

		$option_name             = 'redirect-rows';
		$options[ $option_name ] = (int) $_POST[ $option_name ];

		// Store updated options array to database
		update_option( 'azrcrv-r', $options );

		// Redirect the page to the configuration form that was processed
		wp_redirect( add_query_arg( 'page', 'azrcrv-r&settings-updated', admin_url( 'admin.php' ) ) );
		exit;
	}
}

/**
 * Display Manage Redirect page.
 *
 * @since 1.0.0
 */
function display_manage_redirects() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'redirect' ) );
	}

	global $wpdb;

	// Retrieve plugin configuration options from database
	$options = get_option_with_defaults( 'azrcrv-r' );

	echo '<div id="azrcrv-r-general" class="wrap">';

	?>
		<h1>
			<?php
				echo '<a href="https://development.azurecurve.co.uk/classicpress-plugins/"><img src="' . plugins_url( '/pluginmenu/images/logo.svg', __FILE__ ) . '" style="padding-right: 6px; height: 20px; width: 20px;" alt="azurecurve" /></a>';
				esc_html_e( get_admin_page_title() );
			?>
		</h1>
		
		<?php

		if ( isset( $_GET['redirect-added'] ) ) {
			echo '<div class="notice notice-success is-dismissible">
					<p><strong>' . __( 'Redirect has been added.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['cannot-redirect-home'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Redirect cannot be added for the site home page.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['missing-urls'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Source or destination url is empty.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['already-exists'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Redirect already exists for supplied source url.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['invalid-type'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Invalid redirect type specified.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['not-relative'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Only relative urls can be redirected.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['not-valid'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Only valid pages can be set as a destination.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible">
					<p><strong>' . __( 'Redirect has been deleted.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['redirect-edited'] ) ) {
			echo '<div class="notice notice-success is-dismissible">
					<p><strong>' . __( 'Redirect has been edited.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['delete-failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Delete of redirect failed.', 'redirect' ) . '</strong></p>
				</div>';
		} elseif ( isset( $_GET['edit-failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible">
					<p><strong>' . __( 'Edit of redirect failed.', 'redirect' ) . '</strong></p>
				</div>';
		}

		$tablename = $wpdb->prefix . DATABASE_TABLE;

		$tab_1 = '<h2>' . esc_html__( 'Add New Redirect', 'redirect' ) . '</h2>';

		if ( $options['default-redirect'] == 301 ) {
			$redirect_301 = 'selected=selected';
			$redirect_302 = '';
		} else {
			$redirect_301 = '';
			$redirect_302 = 'selected=selected';
		}

		$id              = 0;
		$source_url      = '';
		$redirect_type   = '';
		$destination_url = '';
		if ( isset( $_GET['id'] ) ) {
			$id = (int) $_GET['id'];

			// $row_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tablename WHERE id = %d", $id));

			$sql = $wpdb->prepare( "SELECT source_url,redirect_type,destination_url FROM $tablename WHERE id = %d LIMIT 0,1", $id );

			$row = $wpdb->get_row( $sql );

			if ( $row ) {
				$tab_1      = '<h2>' . esc_html__( 'Edit Redirect', 'redirect' ) . '</h2>';
				$source_url = $row->source_url;
				if ( $row->redirect_type == 301 ) {
					$redirect_301 = 'selected=selected';
					$redirect_302 = '';
				} else {
					$redirect_301 = '';
					$redirect_302 = 'selected=selected';
				}
				$destination_url = $row->destination_url;
			} else {
				$id = 0;
			}
		}

		$tab_1 .= '<table class="form-table">
					
					<tr>
					
						<th scope="row">
						
							<label for="source-url">
								' . esc_html__( 'Source URL', 'redirect' ) . '
							</label>
							
						</th>
						
						<td>
						
							<input name="source-url" type="text" id="source-url" value="' . $source_url . '" class="large-text" placeholder="' . esc_html__( 'Relative URL to redirect', 'redirect' ) . '" />
							
						</td>
						
					</tr>
		
					<tr>
						<th scope="row">
							
								' . esc_html__( 'Redirect Type', 'redirect' ) . '
							
						</th>
					
						<td>
							
							<select name="redirect-type">
								<option value="301" ' . $redirect_301 . '>301 - Permanent Redirect</option>
								<option value="302" ' . $redirect_302 . '>302 - Temporary Redirect</option>
							</select>
							
						</td>
	
					</tr>
					
					<tr>
					
						<th scope="row">
						
							<label for="destination-url">
								' . esc_html__( 'Destination URL', 'redirect' ) . '
							</label>
							
						</th>
						
						<td>
						
							<input name="destination-url" type="text" id="destination-url" value="' . $destination_url . '" class="large-text" placeholder="' . esc_html__( 'Destination URL for the redirect', 'redirect' ) . '" />
							
						</td>
						
					</tr>
					
				</table>';

		$redirect_rows = (int) $options['redirect-rows'];
		if ( isset( $_GET['p'] ) ) {
			$page_start = $_GET['p'] * $redirect_rows - $redirect_rows;
		} else {
			$page_start = 0;
		}

		$page_end = $redirect_rows;
		$limit    = $page_start . ', ' . $page_end;

		$sql = "SELECT id,source_url,redirect_count,DATE_FORMAT(last_redirect, '%Y-%m-%d') AS last_redirect,status,redirect_type,destination_url FROM $tablename ORDER BY source_url LIMIT " . $limit;

		// echo $sql.'<p />';

		$resultset = $wpdb->get_results( $sql );

		$tab_2 = '<h2>' . esc_html__( 'Current Redirects', 'redirect' ) . '</h2>
		<table class="azrcrv-r">
			<thead>
				<tr>
					<th>
						' . esc_html__( 'Source URL', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Redirects', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Last Redirect', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Status', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Redirect Type', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Destination URL', 'redirect' ) . '
					</th>
					<th>
						' . esc_html__( 'Action', 'redirect' ) . '
					</th>
				<tr>
			</thead>
			<tbody>
				%s
			</tbody>
		</table>';

		$tbody_blank = '<tr colspan=7><td>' . __( 'No redirects found for this page...', 'redirect' ) . '</td></tr>';
		$tbody       = '';
		foreach ( $resultset as $result ) {

			if ( $result->status == 'enabled' ) {
				$redirect_status = __( 'Enabled', 'redirect' );
				$td_class        = 'azrcrv-r-enabled';
			} else {
				$redirect_status = __( 'Disabled', 'redirect' );
				$td_class        = 'azrcrv-r-disabled';
			}

			if ( $result->redirect_type == '301' ) {
				$redirect_type = sprintf( __( '%d - permanent', 'redirect' ), '301' );
			} else {
				$redirect_type = sprintf( __( '%d - temporary', 'redirect' ), '302' );
			}

			$tbody .= '<tr>
					<td>
						<a href="' . esc_attr( $result->source_url, 'redirect' ) . '">' . esc_attr( $result->source_url, 'redirect' ) . '</a>
					</td>
					<td>
						' . esc_attr( $result->redirect_count, 'redirect' ) . '
					</td>
					<td>
						' . esc_attr( $result->last_redirect, 'redirect' ) . '
					</td>
					<td class="' . $td_class . '">
						' . $redirect_status . '
					</td>
					<td>
						' . $redirect_type . '
					</td>
					<td>
						<a href="' . esc_attr( $result->destination_url, 'redirect' ) . '">' . esc_attr( $result->destination_url, 'redirect' ) . '</a>
					</td>
					<td>
						%s
					</td>
				</tr>';

			$page_field = '';
			if ( isset( $_GET['p'] ) ) {
				$page_field = '<input type="hidden" name="p" value="' . esc_html__( stripslashes( $_GET['p'] ) ) . '" class="short-text" />';
			}

			$buttons = '<div style="display: inline-block; "><form method="post" action="admin-post.php">
									
						<input type="hidden" name="action" value="azrcrv_r_manage_redirects" />
						<input name="page_options" type="hidden" value="toggle" />
						<input type="hidden" name="id" value="' . esc_html__( stripslashes( $result->id ) ) . '" class="short-text" />
						' . $page_field . '
						
						' .
							wp_nonce_field( 'azrcrv-r-mr', 'azrcrv-r-mr-nonce', true, false )
						. '
						
						<input type="hidden" name="button_action" value="toggle" class="short-text" />
						<input style="height: 24px; " type="image" src="' . plugin_dir_url( __FILE__ ) . 'assets/images/toggle.svg" id="button_action" name="button_action" title="Toggle Status" alt="Toggle Status" value="toggle" class="arcrv-r"/>
					
					</form>
			</div><div style="display: inline-block; "><form method="post" action="admin-post.php">
									
						<input type="hidden" name="action" value="azrcrv_r_manage_redirects" />
						<input name="page_options" type="hidden" value="edit,delete" />
						<input type="hidden" name="id" value="' . esc_html__( stripslashes( $result->id ) ) . '" class="short-text" />
						' . $page_field . '
						
						' .
							wp_nonce_field( 'azrcrv-r-mr', 'azrcrv-r-mr-nonce', true, false )
						. '
						
						<input type="hidden" name="button_action" value="edit" class="short-text" />
						<input style="height: 24px; " type="image" src="' . plugin_dir_url( __FILE__ ) . 'assets/images/edit.svg" id="button_action" name="button_action" title="Edit" alt="Edit" value="edit" class="arcrv-r"/>
					
					</form>
			</div><div style="display: inline-block; "><form method="post" action="admin-post.php">
									
						<input type="hidden" name="action" value="azrcrv_r_manage_redirects" />
						<input name="page_options" type="hidden" value="edit,delete" />
						<input type="hidden" name="id" value="' . esc_html__( stripslashes( $result->id ) ) . '" class="short-text" />
						' . $page_field . '
						
						' .
							wp_nonce_field( 'azrcrv-r-mr', 'azrcrv-r-mr-nonce', true, false )
						. '
						
					<input type="hidden" name="button_action" value="delete" class="short-text" />
						<input style="height: 24px; " type="image" src="' . plugin_dir_url( __FILE__ ) . 'assets/images/delete.svg" id="button_action" name="button_action" title="Delete" alt="Delete" value="delete" class="arcrv-r"/>
					
					</form>
			</div>';

			$tbody = sprintf( $tbody, $buttons );

		}

		if ( $tbody == '' ) {
			$tbody = $tbody_blank;
		}

		$tab_2 = sprintf( $tab_2, $tbody );

		$id_field = '';
		if ( isset( $_GET['id'] ) ) {
			$id       = (int) $_GET['id'];
			$id_field = '<input type="hidden" name="id" value="' . esc_html__( stripslashes( $id ) ) . '" class="short-text" />';
		}

		?>
		
		<div id="tabs">
			<div id="tab-panel-1" >
				
				<form method="post" action="admin-post.php">
					<fieldset>
								
						<input type="hidden" name="action" value="azrcrv_r_add_redirect" />
						<input name="page_options" type="hidden" value="source-url,redirect-type,destination-url" />
						
						<?php
							echo $page_field;
							echo $id_field;
							// <!-- Adding security through hidden referrer field -->
							wp_nonce_field( 'azrcrv-r-ar', 'azrcrv-r-ar-nonce', true, true );
						?>
						
						<div id="tabs">
							<div id="tab-panel-1" >
								<?php echo $tab_1; ?>
							</div>
						</div>
					</fieldset>
					
					<?php
					if ( $id == 0 ) {
						$button_text = esc_html__( 'Add redirect', 'redirect' );
					} else {
						$button_text = esc_html__( 'Edit redirect', 'redirect' );
					}
					?>
					<input type="submit" name="btn_add" value="<?php echo $button_text; ?>" class="button-primary"/>
				</form>
				
			</div>
			<div id="tab-panel-2" >
				<?php
					echo $tab_2;
					echo '<div class=azrcrv-r-pagination>' . get_pagination( $redirect_rows ) . '</div>';
				?>
			</div>
		</div>
		
	</div>
	<?php

}

/**
 * Manage redirect.
 *
 * @since 1.0.0
 */
function manage_redirects() {
	// Check that user has proper security level
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permissions to perform this action', 'redirect' ) );
	}

	// Check that nonce field created in configuration form is present
	if ( ! empty( $_POST ) && check_admin_referer( 'azrcrv-r-mr', 'azrcrv-r-mr-nonce' ) ) {

		global $wpdb;

		$tablename = $wpdb->prefix . DATABASE_TABLE;

		// Retrieve original plugin options array
		$options = get_option( 'azrcrv-r' );

		$page = '';
		if ( isset( $_POST['p'] ) ) {
			$page = '&p=' . esc_attr( $_POST['p'] );
		}

		$id = (int) $_POST['id'];

		if ( isset( $_POST['button_action'] ) ) {
			if ( $_POST['button_action'] == 'delete' ) {

				$wpdb->delete( $tablename, array( 'id' => esc_attr( $id ) ) );

				wp_redirect( add_query_arg( 'page', 'azrcrv-r-mr&deleted' . $page, admin_url( 'admin.php' ) ) );

			} elseif ( $_POST['button_action'] == 'edit' ) {

				wp_redirect( add_query_arg( 'page', 'azrcrv-r-mr&edit&id=' . $id . $page, admin_url( 'admin.php' ) ) );

			} else {

				$sql = "UPDATE $tablename SET status = CASE WHEN status = 'enabled' THEN 'disabled' ELSE 'enabled' END WHERE id = %d";

				$wpdb->query( $wpdb->prepare( $sql, esc_attr( $id ) ) );

				wp_redirect( add_query_arg( 'page', 'azrcrv-r-mr&refresh' . $page, admin_url( 'admin.php' ) ) );

			}
		}
		exit;
	}
}

/**
 * Display Add Redirect page.
 *
 * @since 1.0.0
 */
function get_pagination( $rowsperpage ) {

	$pagination = '';

	global $wpdb;

	$tablename = $wpdb->prefix . DATABASE_TABLE;

	$range = 5;// how many pages to show in page link

	if ( isset( $_GET['p'] ) && is_numeric( $_GET['p'] ) ) {
		// cast var as int
		$currentpage = (int) $_GET['p'];
	} else {
		// default page num
		$currentpage = 1;
	}

	$sql = "SELECT COUNT(*) FROM $tablename";

	// echo $sql.'<p />';
	$numrows = $wpdb->get_var( "SELECT COUNT(*) FROM $tablename" );

	$totalpages = ceil( $numrows / $rowsperpage );

	if ( $currentpage > $totalpages ) {
		// set current page to last page
		$currentpage = $totalpages;
	}
	// if current page is less than first page...
	if ( $currentpage < 1 ) {
		// set current page to first page
		$currentpage = 1;
	}

	if ( $currentpage > 1 ) {
		// show << link to go back to page 1
		$pagination .= " <a href='admin.php?page=azrcrv-r-mr&p=1'>&laquo;</a> ";
		// get previous page num
		$prevpage = $currentpage - 1;
		// show < link to go back to 1 page
		$pagination .= " <a href='admin.php?page=azrcrv-r-mr&p=$prevpage'>&lsaquo;</a> ";
	}

	// loop to show links to range of pages around current page
	for ( $x = ( $currentpage - $range ); $x < ( ( $currentpage + $range ) + 1 ); $x++ ) {
		// if it's a valid page number...
		if ( ( $x > 0 ) && ( $x <= $totalpages ) ) {
			// if we're on current page...
			if ( $x == $currentpage ) {
				// 'highlight' it but don't make a link
				$pagination .= " [<b>$x</b>] ";
				// if not current page...
			} else {
				// make it a link
				$pagination .= " <a href='admin.php?page=azrcrv-r-mr&p=$x'>$x</a> ";
			}
		}
	}

	// if not on last page, show forward and last page links
	if ( $currentpage != $totalpages ) {
		// get next page
		$nextpage = $currentpage + 1;
		// echo forward link for next page

		$pagination .= " <a href='admin.php?page=azrcrv-r-mr&p=$nextpage'>&rsaquo;</a> ";
		// echo forward link for lastpage
		$pagination .= " <a href='admin.php?page=azrcrv-r-mr&p=$totalpages'>&raquo;</a> ";
	}

	return $pagination;

}

/**
 * Add redirect.
 *
 * @since 1.0.0
 */
function add_redirect() {
	// Check that user has proper security level
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permissions to perform this action', 'redirect' ) );
	}

	// Check that nonce field created in configuration form is present
	if ( ! empty( $_POST ) && check_admin_referer( 'azrcrv-r-ar', 'azrcrv-r-ar-nonce' ) ) {

		global $wpdb;

		// Retrieve original plugin options array
		$options = get_option( 'azrcrv-r' );

		$page = '';
		if ( isset( $_POST['p'] ) ) {
			$page = '&p=' . esc_attr( $_POST['p'] );
		}

		$id = (int) $_POST['id'];

		if ( isset( $_POST['btn_add'] ) ) {

			$before        = trailingslashit( wp_parse_url( sanitize_text_field( $_POST['source-url'] ), PHP_URL_PATH ) );
			$redirect_type = (int) $_POST['redirect-type'];
			$after         = trailingslashit( wp_parse_url( sanitize_text_field( $_POST['destination-url'] ), PHP_URL_PATH ) );

			if ( $id == 0 ) {
				$redirect = check_for_redirect( $before );
			}

			if ( $redirect and $id = 0 ) {
				$message = 'already-exists';
			} elseif ( $before == '/' ) { // is homepage?
				$message = 'cannot-redirect-home';
			} elseif ( $before == '' or $after == '' ) { // both source and destination url required
				$message = 'missing-urls';
			} elseif ( $redirect_type != '301' and $redirect_type != '302' ) { // invalid redirect type
				$message = 'invalid-type';
			} elseif ( wp_make_link_relative( $before ) <> $before ) { // before must be relative
				$message = 'not-relative';
			} else {
				// insert new redirect
				$tablename = $wpdb->prefix . DATABASE_TABLE;

				if ( $id == 0 ) {
					$sql = $wpdb->prepare( "INSERT INTO $tablename (source_url,status,redirect_type,destination_url, added, added_utc) VALUES (%s, 'enabled', %d, %s, Now(), UTC_TIMESTAMP())", $before, $redirect_type, $after );
				} else {
					$sql = $wpdb->prepare( "UPDATE $tablename SET source_url = %s, redirect_type = %d, destination_url = %s WHERE id = %d", $before, $redirect_type, $after, $id );
				}

				$wpdb->query( $sql );

				if ( $id == 0 ) {
					$message = 'redirect-added';
				} else {
					$message = 'redirect-edited';
				}
			}
		}
		wp_redirect( add_query_arg( 'page', 'azrcrv-r-mr&' . $message . $page, admin_url( 'admin.php' ) ) );
		exit;
	}
}

/**
 * Redirect incoming url.
 *
 * @since 1.0.0
 */
function redirect_incoming() {

	global $wpdb;

	$options = get_option_with_defaults( 'azrcrv-r' );

	$url          = esc_url( trailingslashit( strtok( $_SERVER['REQUEST_URI'], '?' ) ) );
	$query_string = $_SERVER['QUERY_STRING'];

	$redirect = check_for_redirect( $url );

	if ( $redirect ) {
		if ( $redirect->status == 'enabled' ) {

			$tablename = $wpdb->prefix . DATABASE_TABLE;
			$sql       = $wpdb->prepare( "UPDATE $tablename SET redirect_count = redirect_count + 1, last_redirect = NOW(), last_redirect_utc = UTC_TIMESTAMP() WHERE ID = '%d'", $redirect->id );

			$wpdb->query( $sql );
			
			if ( strlen( $query_string ) > 0 ){
				$query_string = '?' . $query_string;
			}

			wp_redirect( $redirect->destination_url . $query_string, $redirect->redirect_type );
			exit;

		}
	}

}

/**
 * Identify redirect from url.
 *
 * @since 1.0.0
 */
function check_for_redirect( $url ) {

	global $wpdb;

	$tablename = $wpdb->prefix . DATABASE_TABLE;

	$sql = $wpdb->prepare( "SELECT id,destination_url,redirect_type,status FROM $tablename WHERE source_url = '%s' LIMIT 0,1", $url );

	$redirect = $wpdb->get_row( $sql );

	return $redirect;
}

/**
 * Store previous permalink.
 *
 * @since 1.0.0
 */
function pre_post_update( $post_id, $data ) {

	$options = get_option_with_defaults( 'azrcrv-r' );

	if ( $options['redirect-permalink-changes'] == 1 ) {
		set_transient( 'azrcrv-r-' . $post_id, get_permalink( $post_id ), 60 );
	}

}

/**
 * Add redirect when permalink changes.
 *
 * @since 1.0.0
 */
function add_redirect_for_changed_permalink( $post_id, $post_after, $post_before ) {

	global $wpdb;

	$options = get_option_with_defaults( 'azrcrv-r' );

	if ( $options['redirect-permalink-changes'] == 1 ) {

		$before = trailingslashit( wp_parse_url( get_transient( 'azrcrv-r-' . $post_id ), PHP_URL_PATH ) );
		$after  = trailingslashit( wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ) );

		if ( $before != $after and $before != '/' ) {

			$tablename = $wpdb->prefix . DATABASE_TABLE;

			$redirect = check_for_redirect( $before );

			if ( $redirect ) {

				// insert new redirect
				$sql = $wpdb->prepare( "UPDATE $tablename SET destination_url = %s WHERE id = %d", $after, $redirect->id );

			} else {

				// insert new redirect
				$sql = $wpdb->prepare( "INSERT INTO $tablename (source_url,status,redirect_type,destination_url, added, added_utc) VALUES (%s, 'enabled', %d, %s, Now(), UTC_TIMESTAMP())", $before, esc_attr( $options['default-redirect'] ), $after );

			}

			$wpdb->query( $sql );

		}
	}

}
