<?php
namespace SafeStep;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const CAP  = 'update_plugins';
	const SLUG = 'safestep';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_safestep_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_safestep_release', array( __CLASS__, 'handle_release' ) );
		add_action( 'admin_post_safestep_clear_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	public static function menu(): void {
		add_management_page( 'SafeStep Updates', 'SafeStep', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		register_setting(
			'safestep',
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
			wp_die( esc_html__( 'You are not allowed to do that.', 'safestep-updates' ), 403 );
		}
		check_admin_referer( 'safestep_restore' );

		$file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$id   = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		$snap = Snapshots::find( $file, $id ); // Validates both values against the stored index.
		$cur  = '';

		$result = $snap ? Snapshots::restore( $file, $id ) : new \WP_Error( 'safestep_nosnap', 'Snapshot not found.' );
		if ( is_wp_error( $result ) ) {
			Log::add( $file, 'manual_restore_failed', $cur, $snap['version'] ?? '', $result->get_error_message() );
			wp_safe_redirect( self::page_url( array( 'safestep_msg' => 'restore_failed' ) ) );
			exit;
		}
		Log::add( $file, 'manual_restore', $cur, $snap['version'], 'Restored by ' . wp_get_current_user()->user_login );
		wp_safe_redirect( self::page_url( array( 'safestep_msg' => 'restored' ) ) );
		exit;
	}

	public static function handle_release(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'safestep-updates' ), 403 );
		}
		check_admin_referer( 'safestep_release' );
		$file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		Settings::release( $file );
		wp_safe_redirect( self::page_url( array( 'safestep_msg' => 'released' ) ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'safestep-updates' ), 403 );
		}
		check_admin_referer( 'safestep_clear_log' );
		Log::clear();
		wp_safe_redirect( self::page_url( array( 'safestep_msg' => 'cleared' ) ) );
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
			'restored'       => array( 'success', __( 'Snapshot restored.', 'safestep-updates' ) ),
			'restore_failed' => array( 'error', __( 'Restore failed. See the activity log.', 'safestep-updates' ) ),
			'released'       => array( 'success', __( 'Hold released.', 'safestep-updates' ) ),
			'cleared'        => array( 'success', __( 'Log cleared.', 'safestep-updates' ) ),
		);
		$key = isset( $_GET['safestep_msg'] ) ? sanitize_key( wp_unslash( $_GET['safestep_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>SafeStep Updates</h1>
			<?php if ( isset( $msgs[ $key ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $key ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $key ][1] ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Every plugin update is snapshotted first, then the site is checked. If the update breaks the site, the previous version is restored automatically.', 'safestep-updates' ); ?></p>

			<h2><?php esc_html_e( 'Settings', 'safestep-updates' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'safestep' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="safestep-mode"><?php esc_html_e( 'Auto-update plugins', 'safestep-updates' ); ?></label></th>
						<td>
							<select id="safestep-mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[mode]">
								<option value="off" <?php selected( $settings['mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'safestep-updates' ); ?></option>
								<option value="all" <?php selected( $settings['mode'], 'all' ); ?>><?php esc_html_e( 'All updates', 'safestep-updates' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Every automatic update is snapshotted, checked and rolled back if it breaks the site. Manual updates are protected even with this off.', 'safestep-updates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Never auto-update', 'safestep-updates' ); ?></th>
						<td>
							<?php foreach ( $plugins as $file => $data ) : ?>
								<label style="display:block">
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[excluded][]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, $settings['excluded'], true ) ); ?>>
									<?php echo esc_html( $data['Name'] ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="safestep-urls"><?php esc_html_e( 'Extra pages to check', 'safestep-updates' ); ?></label></th>
						<td>
							<textarea id="safestep-urls" name="<?php echo esc_attr( Settings::OPTION ); ?>[extra_urls]" rows="4" class="large-text code" placeholder="/shop/&#10;/checkout/"><?php echo esc_textarea( $settings['extra_urls'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, same site only. The home page and login page are always checked.', 'safestep-updates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="safestep-email"><?php esc_html_e( 'Notify', 'safestep-updates' ); ?></label></th>
						<td><input id="safestep-email" type="email" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[notify_email]" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="safestep-keep"><?php esc_html_e( 'Snapshots to keep per plugin', 'safestep-updates' ); ?></label></th>
						<td><input id="safestep-keep" type="number" min="1" max="10" name="<?php echo esc_attr( Settings::OPTION ); ?>[keep]" value="<?php echo esc_attr( (string) $settings['keep'] ); ?>"></td>
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
		echo '<h2>' . esc_html__( 'Versions on hold', 'safestep-updates' ) . '</h2>';
		echo '<p>' . esc_html__( 'These versions broke the site and were rolled back. They will not be auto-installed again.', 'safestep-updates' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( $held as $file => $version ) {
			echo '<tr><td>' . esc_html( self::plugin_label( $file ) ) . '</td><td>' . esc_html( $version ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'safestep_release' );
			echo '<input type="hidden" name="action" value="safestep_release">';
			echo '<input type="hidden" name="plugin" value="' . esc_attr( $file ) . '">';
			echo '<button class="button">' . esc_html__( 'Release hold', 'safestep-updates' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_snapshots( array $plugins ): void {
		echo '<h2>' . esc_html__( 'Snapshots', 'safestep-updates' ) . '</h2>';
		$any = false;
		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Plugin', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Version', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Taken', 'safestep-updates' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Snapshots::index() as $file => $entries ) {
			foreach ( array_reverse( (array) $entries ) as $e ) {
				$any  = true;
				$name = isset( $plugins[ $file ]['Name'] ) ? $plugins[ $file ]['Name'] : $file;
				echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $e['version'] ) . '</td>';
				/* translators: %s: time since the snapshot was taken, e.g. "2 hours" */
				echo '<td>' . esc_html( sprintf( __( '%s ago', 'safestep-updates' ), human_time_diff( (int) $e['time'] ) ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Replace the current plugin files with this snapshot?', 'safestep-updates' ) ) . '\')">';
				wp_nonce_field( 'safestep_restore' );
				echo '<input type="hidden" name="action" value="safestep_restore">';
				echo '<input type="hidden" name="plugin" value="' . esc_attr( $file ) . '">';
				echo '<input type="hidden" name="snapshot" value="' . esc_attr( $e['id'] ) . '">';
				echo '<button class="button">' . esc_html__( 'Restore', 'safestep-updates' ) . '</button></form></td></tr>';
			}
		}
		if ( ! $any ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No snapshots yet. One is taken before each plugin update.', 'safestep-updates' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_log(): void {
		$labels = array(
			'ok'                    => __( 'Updated OK', 'safestep-updates' ),
			'rolled_back'           => __( 'Rolled back', 'safestep-updates' ),
			'rollback_failed'       => __( 'Rollback FAILED', 'safestep-updates' ),
			'snapshot_failed'       => __( 'No snapshot', 'safestep-updates' ),
			'manual_restore'        => __( 'Manual restore', 'safestep-updates' ),
			'manual_restore_failed' => __( 'Restore failed', 'safestep-updates' ),
		);
		echo '<h2>' . esc_html__( 'Activity', 'safestep-updates' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Plugin', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Result', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Change', 'safestep-updates' ) . '</th><th>' . esc_html__( 'Details', 'safestep-updates' ) . '</th></tr></thead><tbody>';
		$log = array_reverse( Log::all() );
		if ( ! $log ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing yet.', 'safestep-updates' ) . '</td></tr>';
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
		wp_nonce_field( 'safestep_clear_log' );
		echo '<input type="hidden" name="action" value="safestep_clear_log">';
		echo '<button class="button">' . esc_html__( 'Clear log', 'safestep-updates' ) . '</button></form>';
	}
}
