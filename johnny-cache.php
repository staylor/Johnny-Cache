<?php

/**
 * Plugin Name: Johnny Cache
 * Plugin URI:  http://emusic.com
 * Author:      Scott Taylor ( wonderboymusic )
 * Description: UI for managing Batcache / Memcached WP Object Cache backend
 * Author URI:  http://scotty-t.com
 * Version:     2.0.0
*/

class JohnnyCache {

	/**
	 * The URL used for assets
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $url = '';

	/**
	 * The version used for assets
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $version = '201512250001';

	/**
	 * Nonce ID for getting the Memcached instance
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $get_instance_nonce = 'jc-get_instance';

	/**
	 * Nonce ID for flushing a Memcache group
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $flush_group_nonce = 'jc-flush_group';

	/**
	 * Nonce ID for removing an item from Memcache
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $remove_item_nonce = 'jc-remove_item';

	/**
	 * Nonce ID for retrieving an item from Memcache
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $get_item_nonce = 'jc-get_item';

	/**
	 * The main constructor
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		// Setup the plugin URL, for enqueues
		$this->url = plugin_dir_url( __FILE__ );

		// WP
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// AJAX
		add_action( 'wp_ajax_jc-get-item',     array( $this, 'ajax_get_mc_item'     ) );
		add_action( 'wp_ajax_jc-get-instance', array( $this, 'ajax_get_mc_instance' ) );
		add_action( 'wp_ajax_jc-flush-group',  array( $this, 'ajax_flush_mc_group'  ) );
		add_action( 'wp_ajax_jc-remove-item',  array( $this, 'ajax_remove_mc_item'  ) );

		// JC
		add_action( 'johnny_cache_notice', array( $this, 'notice' ) );
	}

	/**
	 * Add the top-level admin menu
	 *
	 * @since 2.0.0
	 */
	public function admin_menu() {

		// Add menu page
		$this->hook = add_menu_page(
			esc_html__( 'Johnny Cache', 'johnny-cache' ),
			esc_html__( 'Johnny Cache', 'johnny-cache' ),
			'manage_cache', // Single-site admins and multi-site super admins
			'johnny-cache',
			array( $this, 'page' ),
			'dashicons-album'
		);

		// Load page on hook
		add_action( "load-{$this->hook}", array( $this, 'load' ) );

		// Enqueue assets, not by hook unfortunately
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
	}

	/**
	 * Enqueue assets
	 *
	 * @since 2.0.0
	 */
	public function admin_enqueue() {

		// Bail if not this page
		if ( $GLOBALS['page_hook'] !== $this->hook ) {
			return;
		}

		// Use thickboxes
		add_thickbox();

		// Enqueue
		wp_enqueue_style( 'johnny-cache', $this->url . 'assets/css/johnny-cache.css', array(), $this->version );
		wp_enqueue_script( 'johnny-cache', $this->url . 'assets/js/johnny-cache.js', array(), $this->version, true );

		// Localize JS
		wp_localize_script( 'johnny-cache', 'JohnnyCache', array(
			'no_results'         => $this->get_no_results_row(),
			'refreshing_results' => $this->get_refreshing_results_row()
		) );
	}

	/**
	 * Maybe clear a cache group, based on user request
	 *
	 * @since 2.0.0
	 *
	 * @param bool $redirect
	 */
	private function maybe_clear_cache_group( $redirect = true ) {

		// Bail if not clearing
		if ( empty( $_GET['cache_group'] ) ) {
			return;
		}

		// Clear the cache group
		$cleared = $this->clear_group( $_GET['cache_group'] );

		// Bail if not redirecting
		if ( false === $redirect ) {
			return;
		}

		// Assemble the URL
		$url = add_query_arg( array(
			'keys_cleared'  => $cleared,
			'cache_cleared' => $_GET['cache_group']
		), menu_page_url( 'johnny-cache', false ) );

		// Redirect
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Maybe clear a user's entire cache, based on user request
	 *
	 * @since 2.0.0
	 *
	 * @param bool $redirect
	 */
	private function maybe_clear_user_cache( $redirect = true ) {

		// Clear user ID
		if ( empty( $_GET['user_id'] ) ) {
			return;
		}

		// Delete user caches
		$_user = get_user_by( 'id', $_GET['user_id'] );

		// Delete caches
		wp_cache_delete( $_GET['user_id'],      'users'      );
		wp_cache_delete( $_GET['user_id'],      'user_meta'  );
		wp_cache_delete( $_user->user_login,    'userlogins' );
		wp_cache_delete( $_user->user_nicename, 'userslugs'  );
		wp_cache_delete( $_user->user_email,    'useremail'  );

		// Bail if not redirecting
		if ( false === $redirect ) {
			return;
		}

		// Assemble the URL
		$url = add_query_arg( array(
			'keys_cleared'  => '2',
			'cache_cleared' => $_GET['user_id']
		), menu_page_url( 'johnny-cache', false ) );

		// Redirect
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Helper function to check nonce and avoid caching the request
	 *
	 * @since 2.0.0
	 *
	 * @param string $nonce
	 */
	private function check_nonce( $nonce = '' ) {
		check_ajax_referer( $nonce , 'nonce' );

		nocache_headers();
	}

	/**
	 * Attempt to output the server cache contents
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_mc_instance() {
		$this->check_nonce( $this->get_instance_nonce );

		// Attempt to output the server contents
		if ( ! empty( $_POST['name'] ) ) {
			$this->do_instance( $_POST['name'] );
		}

		exit();
	}

	/**
	 * Delete all cache keys in a cache group
	 *
	 * @since 2.0.0
	 */
	public function ajax_flush_mc_group() {
		$this->check_nonce( $this->flush_group_nonce );

		// Loop through keys and attempt to delete them
		if ( ! empty( $_POST['keys'] ) && ! empty( $_GET['group'] ) ) {
			foreach ( $_POST['keys'] as $key ) {
				wp_cache_delete( $key, $_GET['group'] );
			}
		}

		exit();
	}

	/**
	 * Delete a single cache key in a specific group
	 *
	 * @since 2.0.0
	 */
	public function ajax_remove_mc_item() {
		$this->check_nonce( $this->remove_item_nonce );

		// Delete a key in a group
		if ( ! empty( $_GET['key'] ) && ! empty( $_GET['group'] ) ) {
			wp_cache_delete( $_GET['key'], $_GET['group'] );
		}

		exit();
	}

	/**
	 * Attempt to get a cached item
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_mc_item() {
		$this->check_nonce( $this->get_item_nonce );

		// Bail if invalid posted data
		if ( ! empty( $_GET['key'] ) && ! empty( $_GET['group'] ) ) {
			$this->do_item( $_GET['key'], $_GET['group'] );
		}

		exit();
	}

	/**
	 * Clear all of the items in a cache group
	 *
	 * @since 2.0.0
	 *
	 * @global WP_Object_cache $wp_object_cache
	 * @param string $group
	 * @return int
	 */
	public function clear_group( $group = '' ) {
		global $wp_object_cache;

		// Setup counter
		$cleared = 0;

		foreach ( $wp_object_cache->getServerList() as $server ) {
			$port = empty( $server[1] ) ? 11211 : $server['port'];
			$list = $this->retrieve_keys( $server['host'], $port );

			foreach ( $list as $item ) {
				if ( strstr( $item, $group . ':' ) ) {
					$wp_object_cache->mc->delete( $item );
					$cleared++;
				}
			}
		}

		// Return count
		return $cleared;
	}

	/**
	 * Check for actions
	 *
	 * @since 2.0.0
	 */
	public function load() {
		$this->maybe_clear_cache_group( true );
		$this->maybe_clear_user_cache( true );
	}

	/**
	 * Get all cache keys on a server
	 *
	 * @since 2.0.0
	 *
	 * @param  string $server
	 * @param  int    $port
	 *
	 * @return array
	 */
	public function retrieve_keys( $server, $port = 11211 ) {

		// Connect to Memcache
		$memcache = new Memcache();
		$memcache->connect( $server, $port );

		// Get slabs
		$slabs = $memcache->getExtendedStats( 'slabs' );
		$list  = array();

		// Loop through servers to get slabs
		foreach ( $slabs as $server => $slabs ) {

			// Loop through slabs to target single slabs
			foreach ( array_keys( $slabs ) as $slab_id ) {

				// Skip if slab ID is empty
				if ( empty( $slab_id ) ) {
					continue;
				}

				// Get the entire slab
				$cache_dump = $memcache->getExtendedStats( 'cachedump', (int) $slab_id );

				// Loop through slab to find keys
				foreach ( $cache_dump as $slab_dump ) {

					// Skip if key isn't an array (how'd that happen?)
					if ( ! is_array( $slab_dump ) ) {
						continue;
					}

					// Loop through keys and add to list
					foreach( array_keys( $slab_dump ) as $k ) {
						$list[] = $k;
					}
				}
			}
		}

		// Return the list of Memcache server slab keys
		return $list;
	}

	/**
	 * Output the contents of a cached item into a textarea
	 *
	 * @since 2.0.0
	 *
	 * @param  string  $key
	 * @param  string  $group
	 */
	public function do_item( $key, $group ) {

		// Get results directly from Memcached
		$cache   = wp_cache_get( $key, $group );
		$full    = wp_cache_get_key( $key, $group );
		$code    = wp_cache_get_result_code();
		$message = wp_cache_get_result_message();

		// @todo Something prettier with cached value
		$value   = is_array( $cache ) || is_object( $cache )
			? serialize( $cache )
			: $cache;

		// Not found?
		if ( false === $value ) {
			$value = 'ERR';
		}

		// Combine results
		$results =
			sprintf( __( 'Key:     %s',      'johnny-cache' ), $key            ) . "\n" .
			sprintf( __( 'Group:   %s',      'johnny-cache' ), $group          ) . "\n" .
			sprintf( __( 'Full:    %s',      'johnny-cache' ), $full           ) . "\n" .
			sprintf( __( 'Code:    %s - %s', 'johnny-cache' ), $code, $message ) . "\n" .
			sprintf( __( 'Value:   %s',      'johnny-cache' ), $value          ); ?>

		<textarea class="jc-item" class="widefat" rows="10" cols="35"><?php echo $results; ?></textarea>

		<?php
	}

	/**
	 * Output a link used to flush an entire cache group
	 *
	 * @since 0.2.0
	 *
	 * @param int    $blog_id
	 * @param string $group
	 * @param string $nonce
	 */
	private function get_flush_group_link( $blog_id, $group, $nonce ) {

		// Setup the URL
		$url = add_query_arg( array(
			'action'  => 'jc-flush-group',
			'blog_id' => $blog_id,
			'group'   => $group,
			'nonce'   => $nonce
		), admin_url( 'admin-ajax.php' ) );

		// Start the output buffer
		ob_start(); ?>

		<a class="jc-flush-group" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Flush Group', 'johnny-cache' ); ?></a>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Get the map of cache groups $ keys
	 *
	 * @since 2.0.0
	 *
	 * @param  string $server
	 * @return array
	 */
	private function get_keymaps( $server = '' ) {
		global $wp_object_cache;

		// Set an empty keymap array
		$keymaps = array();
		$offset  = 0;

		// Offset by 1 if using cache-key salt
		if ( ! empty( $wp_object_cache->cache_key_salt ) ) {
			$offset = 1;
		}

		// Get keys for this server and loop through them
		foreach ( $this->retrieve_keys( $server ) as $item ) {

			// Skip if CLIENT_ERROR or malforwed [sic]
			if ( empty( $item ) || ! strstr( $item, ':' ) ) {
				continue;
			}

			// Separate the item into parts
			$parts = explode( ':', $item );

			// Remove key salts
			if ( $offset > 0 ) {
				$parts = array_slice( $parts, $offset );
			}

			// Multisite means first part is numeric
			if ( is_numeric( $parts[ 0 ] ) ) {
				$blog_id = $parts[ 0 ];
				$group   = $parts[ 1 ];
				$global  = false;

			// Single site or global cache group
			} else {
				$blog_id = 0;
				$group   = $parts[ 0 ];
				$global  = true;
			}

			// Build the cache key based on number of parts
			if ( ( count( $parts ) === 1 ) ) {
				$key = $parts[ 0 ];
			} else {
				if ( true === $global ) {
					$key = implode( ':', array_slice( $parts, 1 ) );
				} else {
					$key = implode( ':', array_slice( $parts, 2 ) );
				}
			}

			// Build group key by combining blog ID & group
			$group_key = $blog_id . $group;

			// Build the keymap
			if ( isset( $keymaps[ $group_key ] ) ) {
				$keymaps[ $group_key ]['keys'][] = $key;
			} else {
				$keymaps[ $group_key ] = array(
					'blog_id' => $blog_id,
					'group'   => $group,
					'keys'    => array( $key ),
					'item'    => $item
				);
			}
		}

		// Sort the keymaps by key
		ksort( $keymaps );

		return $keymaps;
	}

	/**
	 * Output contents of cache group keys
	 *
	 * @since 2.0.0
	 *
	 * @param int    $blog_id
	 * @param string $group
	 * @param array  $keys
	 */
	private function get_cache_key_links( $blog_id = 0, $group = '', $keys = array() ) {

		// Setup variables used in the loop
		$remove_item_nonce = wp_create_nonce( $this->remove_item_nonce );
		$get_item_nonce    = wp_create_nonce( $this->get_item_nonce    );
		$admin_url         = admin_url( 'admin-ajax.php' );

		// Start the output buffer
		ob_start();

		// Loop through keys and output data & action links
		foreach ( $keys as $key ) :

			// Get URL
			$get_url = add_query_arg( array(
				'action'  => 'jc-get-item',
				'key'     => $key,
				'blog_id' => $blog_id,
				'group'   => $group,
				'nonce'   => $get_item_nonce,
				'width'    => 500,
				'height'   => 500,
				'inlineId' => 'jc-show-item'
			), "{$admin_url}#TB_Inline" );

			// Remove URL
			$remove_url = add_query_arg( array(
				'action'  => 'jc-remove-item',
				'key'     => $key,
				'blog_id' => $blog_id,
				'group'   => $group,
				'nonce'   => $remove_item_nonce
			), $admin_url ); ?>

			<div class="item" data-key="<?php echo esc_attr( $key ); ?>">
				<code><?php echo implode( '</code> : <code>', explode( ':', $key ) ); ?></code>
				<div class="row-actions">
					<span class="trash">
						<a class="jc-remove-item" href="<?php echo esc_url( $remove_url ); ?>"><?php esc_html_e( 'Remove', 'johnny-cache' ); ?></a>
					</span>
					| <a class="jc-view-item" href="<?php echo esc_url( $get_url ); ?>"><?php esc_html_e( 'View', 'johnny-cache' ); ?></a>
				</div>
			</div>

			<?php
		endforeach;

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Output the WordPress admin page
	 *
	 * @since 2.0.0
	 *
	 * @global WP_Object_cache $wp_object_cache
	 */
	public function page() {
		global $wp_object_cache;

		$get_instance_nonce = wp_create_nonce( $this->get_instance_nonce ); ?>

		<div class="wrap johnny-cache" id="jc-wrapper">
			<h2><?php esc_html_e( 'Johnny Cache', 'johnny-cache' ); ?></h2>

			<?php do_action( 'johnny_cache_notice' ); ?>

			<div class="wp-filter">
				<div class="jc-toolbar-secondary">
					<select class="jc-server-selector" data-nonce="<?php echo $get_instance_nonce ?>">
						<option value=""><?php esc_html_e( 'Select a Server', 'johnny-cache' ); ?></option>

						<?php foreach ( $wp_object_cache->mc->getServerList() as $server ) : ?>

							<option value="<?php echo esc_attr( $server['host'] ); ?>"><?php echo esc_html( $server['host'] ); ?></option>

						<?php endforeach ?>

					</select>
					<button class="button action jc-refresh-instance"><?php esc_html_e( 'Refresh', 'johnny-cache' ); ?></button>
				</div>
				<div class="jc-toolbar-primary search-form">
					<label for="jc-search-input" class="screen-reader-text"><?php esc_html_e( 'Search Cache', 'johnny-cache' ); ?></label>
					<input type="search" placeholder="<?php esc_html_e( 'Search', 'johnny-cache' ); ?>" id="jc-search-input" class="search">
				</div>
			</div>

			<div id="jc-show-item"></div>

			<div class="tablenav top">
				<div class="alignleft action">
					<?php // Bulk actions? ?>
				</div>
				<div class="alignright">
					<form action="<?php menu_page_url( 'johnny-cache' ) ?>" method="get">
						<input type="hidden" name="page" value="johnny-cache">
						<input type="text" name="cache_group" />
						<button class="button"><?php esc_html_e( 'Clear Cache Group', 'johnny-cache' ); ?></button>
					</form>
					<form action="<?php menu_page_url( 'johnny-cache' ) ?>" method="get">
						<input type="hidden" name="page" value="johnny-cache">
						<input type="text" name="user_id" />
						<button class="button"><?php esc_html_e( 'Clear User Cache', 'johnny-cache' ); ?></button>
					</form>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped posts">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'johnny-cache' ); ?></label>
							<input id="cb-select-all-1" type="checkbox">
						</td>
						<th class="blog-id"><?php esc_html_e( 'Blog ID', 'johnny-cache' ); ?></th>
						<th class="cache-group"><?php esc_html_e( 'Cache Group', 'johnny-cache' ); ?></th>
						<th class="keys"><?php esc_html_e( 'Keys', 'johnny-cache' ); ?></th>
						<th class="count"><?php esc_html_e( 'Count', 'johnny-cache' ); ?></th>
					</tr>
				</thead>

				<tbody class="jc-contents">
					<?php echo $this->get_no_results_row(); ?>
				</tbody>

				<tfoot>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="cb-select-all-2"><?php esc_html_e( 'Select All', 'johnny-cache' ); ?></label>
							<input id="cb-select-all-2" type="checkbox">
						</td>
						<th class="blog-id"><?php esc_html_e( 'Blog ID', 'johnny-cache' ); ?></th>
						<th class="cache-group"><?php esc_html_e( 'Cache Group', 'johnny-cache' ); ?></th>
						<th class="keys"><?php esc_html_e( 'Keys', 'johnny-cache' ); ?></th>
						<th class="count"><?php esc_html_e( 'Count', 'johnny-cache' ); ?></th>
					</tr>
				</tfoot>
			</table>
		</div>

	<?php
	}

	/**
	 * Output the Memcache server contents in a table
	 *
	 * @since 2.0.0
	 *
	 * @param string $server
	 */
	public function do_rows( $server = '' ) {

		// Setup the nonce
		$flush_group_nonce = wp_create_nonce( $this->flush_group_nonce );

		// Get server key map & output groups in rows
		foreach ( $this->get_keymaps( $server ) as $values ) {
			$this->do_row( $values, $flush_group_nonce );
		}
	}

	/**
	 * Output a table row based on values
	 *
	 * @since 2.0.0
	 *
	 * @param  array   $values
	 * @param  string  $flush_group_nonce
	 */
	private function do_row( $values = array(), $flush_group_nonce = '' ) {
		?>

		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $values['group'] ); ?>" id="checkbox_<?php echo esc_attr( $values['group'] ); ?>">
				<label class="screen-reader-text" for="checkbox_<?php echo esc_attr( $values['group'] ); ?>"><?php esc_html_e( 'Select', 'johnny-cache' ); ?></label>
			</th>
			<td>
				<code><?php echo esc_html( $values['blog_id'] ); ?></code>
			</td>
			<td>
				<span class="row-title"><?php echo esc_html( $values['group'] ); ?></span>
				<div class="row-actions"><span class="trash"><?php echo $this->get_flush_group_link( $values['blog_id'], $values['group'], $flush_group_nonce ); ?></span></div>
			</td>
			<td>
				<?php echo $this->get_cache_key_links( $values['blog_id'], $values['group'], $values['keys'] ); ?>
			</td>
			<td>
				<?php echo number_format_i18n( count( $values['keys'] ) ); ?>
			</td>
		</tr>

	<?php
	}

	/**
	 * Returns a table row used to show no results were found
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function get_no_results_row() {

		// Buffer
		ob_start(); ?>

		<tr class="jc-no-results">
			<td colspan="5">
				<?php esc_html_e( 'No results found.', 'johnny-cache' ); ?>
			</td>
		</tr>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Returns a table row used to show results are loading
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function get_refreshing_results_row() {

		// Buffer
		ob_start(); ?>

		<tr class="jc-refreshing-results">
			<td colspan="5">
				<?php esc_html_e( 'Refreshing...', 'johnny-cache' ); ?>
			</td>
		</tr>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Maybe output a notice to the user that action has taken place
	 *
	 * @since 2.0.0
	 */
	public function notice() {

		// Using cache key salt
		if ( defined( 'WP_CACHE_KEY_SALT' ) && ! empty( WP_CACHE_KEY_SALT ) ) : ?>

			<div id="message" class="notice notice-info">
				<p><?php printf( esc_html__( 'Using cache-key salt: %s', 'johnny-cache' ), WP_CACHE_KEY_SALT ); ?></p>
			</div>

		<?php endif;

		// Bail if no notice
		if ( ! isset( $_GET['cache_cleared'] ) ) {
			return;
		}

		// Cleared
		$keys = isset( $_GET['keys_cleared'] )
			? (int) $_GET['keys_cleared']
			: 0;

		// Cache
		$cache = isset( $_GET['cache_cleared'] )
			? $_GET['cache_cleared']
			: 'none returned';

		// Assemble the message
		$message = sprintf(
			esc_html__( 'Cleared %s keys from %s group(s).', 'johnny-cache' ),
			'<strong>' . esc_html( $keys  ) . '</strong>',
			'<strong>' . esc_html( $cache ) . '</strong>'
		);

		// Cache cleared
		?>

		<div id="message" class="notice notice-success">
			<p><?php echo $message; ?></p>
		</div>

		<?php
	}
}

// I focus on the pain. The only thing that's real.
new JohnnyCache();
