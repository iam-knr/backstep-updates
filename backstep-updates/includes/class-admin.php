<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const CAP  = 'update_plugins';
	const SLUG = 'backstep';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_backstep_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_backstep_release', array( __CLASS__, 'handle_release' ) );
		add_action( 'admin_post_backstep_clear_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	public static function menu(): void {
		add_management_page( 'Backstep Updates', 'Backstep', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		register_setting(
			'backstep',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/* ------------------------------ actions ------------------------------ */

	public static function handle_restore(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'backstep-updates' ), 403 );
		}
		check_admin_referer( 'backstep_restore' );

		$file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$id   = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		$snap = Snapshots::find( $file, $id ); // Validates both values against the stored index.
		$cur  = '';

		$result = $snap ? Snapshots::restore( $file, $id ) : new \WP_Error( 'backstep_nosnap', 'Snapshot not found.' );
		if ( is_wp_error( $result ) ) {
			Log::add( $file, 'manual_restore_failed', $cur, $snap['version'] ?? '', $result->get_error_message() );
			wp_safe_redirect( self::page_url( array( 'backstep_msg' => 'restore_failed' ) ) );
			exit;
		}
		Log::add( $file, 'manual_restore', $cur, $snap['version'], 'Restored by ' . wp_get_current_user()->user_login );
		wp_safe_redirect( self::page_url( array( 'backstep_msg' => 'restored' ) ) );
		exit;
	}

	public static function handle_release(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'backstep-updates' ), 403 );
		}
		check_admin_referer( 'backstep_release' );
		$file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		Settings::release( $file );
		wp_safe_redirect( self::page_url( array( 'backstep_msg' => 'released' ) ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'backstep-updates' ), 403 );
		}
		check_admin_referer( 'backstep_clear_log' );
		Log::clear();
		wp_safe_redirect( self::page_url( array( 'backstep_msg' => 'cleared' ) ) );
		exit;
	}

	/* ------------------------------- views ------------------------------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$settings = Settings::get();
		$plugins  = get_plugins();
		$msgs     = array(
			'restored'       => array( 'success', __( 'Snapshot restored.', 'backstep-updates' ) ),
			'restore_failed' => array( 'error', __( 'Restore failed. See the activity log.', 'backstep-updates' ) ),
			'released'       => array( 'success', __( 'Hold released.', 'backstep-updates' ) ),
			'cleared'        => array( 'success', __( 'Log cleared.', 'backstep-updates' ) ),
		);
		$key = isset( $_GET['backstep_msg'] ) ? sanitize_key( wp_unslash( $_GET['backstep_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Backstep Updates</h1>
			<?php if ( isset( $msgs[ $key ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $key ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $key ][1] ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Every plugin update is snapshotted first, then the site is checked. If the update breaks the site, the previous version is restored automatically.', 'backstep-updates' ); ?></p>

			<h2><?php esc_html_e( 'Settings', 'backstep-updates' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'backstep' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="backstep-urls"><?php esc_html_e( 'Extra pages to check', 'backstep-updates' ); ?></label></th>
						<td>
							<textarea id="backstep-urls" name="<?php echo esc_attr( Settings::OPTION ); ?>[extra_urls]" rows="4" class="large-text code" placeholder="/shop/&#10;/checkout/"><?php echo esc_textarea( $settings['extra_urls'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, same site only. The home page and login page are always checked.', 'backstep-updates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="backstep-email"><?php esc_html_e( 'Notify', 'backstep-updates' ); ?></label></th>
						<td><input id="backstep-email" type="email" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[notify_email]" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="backstep-keep"><?php esc_html_e( 'Snapshots to keep per plugin', 'backstep-updates' ); ?></label></th>
						<td><input id="backstep-keep" type="number" min="1" max="10" name="<?php echo esc_attr( Settings::OPTION ); ?>[keep]" value="<?php echo esc_attr( (string) $settings['keep'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php self::render_held(); ?>
			<?php self::render_snapshots( $plugins ); ?>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	/** Friendly plugin name for a plugin file, falling back to the file path. */
	private static function plugin_label( string $file ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return ( isset( $all[ $file ]['Name'] ) && '' !== $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file;
	}

	private static function render_held(): void {
		$held = Settings::held();
		if ( ! $held ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Versions on hold', 'backstep-updates' ) . '</h2>';
		echo '<p>' . esc_html__( 'These versions broke the site and were rolled back. They will not be auto-installed again.', 'backstep-updates' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( $held as $file => $version ) {
			echo '<tr><td>' . esc_html( self::plugin_label( $file ) ) . '</td><td>' . esc_html( $version ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'backstep_release' );
			echo '<input type="hidden" name="action" value="backstep_release">';
			echo '<input type="hidden" name="plugin" value="' . esc_attr( $file ) . '">';
			echo '<button class="button">' . esc_html__( 'Release hold', 'backstep-updates' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_snapshots( array $plugins ): void {
		echo '<h2>' . esc_html__( 'Snapshots', 'backstep-updates' ) . '</h2>';
		$any = false;
		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Plugin', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Version', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Taken', 'backstep-updates' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Snapshots::index() as $file => $entries ) {
			foreach ( array_reverse( (array) $entries ) as $e ) {
				$any  = true;
				$name = isset( $plugins[ $file ]['Name'] ) ? $plugins[ $file ]['Name'] : $file;
				echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $e['version'] ) . '</td>';
				/* translators: %s: time since the snapshot was taken, e.g. "2 hours" */
				echo '<td>' . esc_html( sprintf( __( '%s ago', 'backstep-updates' ), human_time_diff( (int) $e['time'] ) ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Replace the current plugin files with this snapshot?', 'backstep-updates' ) ) . '\')">';
				wp_nonce_field( 'backstep_restore' );
				echo '<input type="hidden" name="action" value="backstep_restore">';
				echo '<input type="hidden" name="plugin" value="' . esc_attr( $file ) . '">';
				echo '<input type="hidden" name="snapshot" value="' . esc_attr( $e['id'] ) . '">';
				echo '<button class="button">' . esc_html__( 'Restore', 'backstep-updates' ) . '</button></form></td></tr>';
			}
		}
		if ( ! $any ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No snapshots yet. One is taken before each plugin update.', 'backstep-updates' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_log(): void {
		$labels = array(
			'ok'                    => __( 'Updated OK', 'backstep-updates' ),
			'rolled_back'           => __( 'Rolled back', 'backstep-updates' ),
			'rollback_failed'       => __( 'Rollback FAILED', 'backstep-updates' ),
			'snapshot_failed'       => __( 'No snapshot', 'backstep-updates' ),
			'manual_restore'        => __( 'Manual restore', 'backstep-updates' ),
			'manual_restore_failed' => __( 'Restore failed', 'backstep-updates' ),
		);
		echo '<h2>' . esc_html__( 'Activity', 'backstep-updates' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Plugin', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Result', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Change', 'backstep-updates' ) . '</th><th>' . esc_html__( 'Details', 'backstep-updates' ) . '</th></tr></thead><tbody>';
		$log = array_reverse( Log::all() );
		if ( ! $log ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing yet.', 'backstep-updates' ) . '</td></tr>';
		}
		foreach ( $log as $row ) {
			$label = isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'];
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $row['time'] ) ) . '</td>';
			echo '<td>' . esc_html( self::plugin_label( (string) $row['plugin'] ) ) . '</td><td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( trim( $row['from'] . ' → ' . $row['to'], ' →' ) ) . '</td>';
			echo '<td>' . esc_html( $row['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( 'backstep_clear_log' );
		echo '<input type="hidden" name="action" value="backstep_clear_log">';
		echo '<button class="button">' . esc_html__( 'Clear log', 'backstep-updates' ) . '</button></form>';
	}
}
