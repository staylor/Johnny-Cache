<?php
/*
Plugin Name: Johnny Cache
Plugin URI: http://emusic.com
Author: Scott Taylor ( wonderboymusic )
Description: UI for managing Batcache / Memcached WP Object Cache backend
Author URI: http://scotty-t.com
Version: 0.1
*/

class JohnnyCache {
	/**#@+
	 * 
	 * @access private
	 * @var string
	 */
	private $get_instance_nonce = 'jc-get_instance';
	private $remove_item_nonce = 'jc-remove_item';
	private $flush_group_nonce = 'jc-flush_group';
	private $get_item_nonce = 'jc-get_item';
	/**#@-*/
	/**
	 * @staticvar instance Holds Singleton instance of class
	 */
	private static $instance;
    
	/**
	 * Singleton accessor
	 * 
	 * @uses get_called_class()
	 * @return BasePlugin the subclass instance
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) )
			self::$instance = new JohnnyCache();

		return self::$instance;
	}	
	/**
	 * Filters
	 * 
	 */
	protected function __construct() {
		add_action( 'admin_menu',               array( $this, 'page' ) );
		add_action( 'wp_ajax_jc-flush-group',   array( $this, 'ajax_flush_group' ) );
		add_action( 'wp_ajax_jc-remove-item',   array( $this, 'ajax_remove_item' ) );
		add_action( 'wp_ajax_jc-get-instance',  array( $this, 'ajax_get_instance' ) );
		add_action( 'wp_ajax_jc-get-item',      array( $this, 'ajax_get_item' ) );
	}

	/**
	 * Get Memcached instance as HTML table
	 * 
	 */
	function ajax_get_instance() {
		check_ajax_referer( $this->get_instance_nonce, 'nonce' );
		extract( $_REQUEST, EXTR_SKIP );

		nocache_headers();

		$this->do_instance( $name );
		exit();
	}
	/**
	 * Get contents of item in cache
	 * 
	 */
	function ajax_get_item() {
		check_ajax_referer( $this->get_item_nonce, 'nonce' );
		extract( $_REQUEST, EXTR_SKIP );

		nocache_headers();

		$this->do_item( $key, $group );
		exit();
	}
	/**
	 * Delete all cache keys in a group
	 * 
	 */    
	function ajax_flush_group() {
		check_ajax_referer( $this->flush_group_nonce, 'nonce' );
		extract( $_REQUEST, EXTR_SKIP );

		nocache_headers();

		foreach ( $keys as $key ) {
			wp_cache_delete( $key, $group );
		}
		exit();
	}
	/**
	 * Delete an item from the cache
	 * 
	 */
	function ajax_remove_item() {
		check_ajax_referer( $this->remove_item_nonce, 'nonce' );
		extract( $_REQUEST, EXTR_SKIP );

		nocache_headers();

		wp_cache_delete( $key, $group );
		exit();
	}

	/**
	 * Register menu page in the admin
	 * 
	 */
	function page() {
		$hook = add_menu_page( __( 'Johnny Cache', 'johnny-cache' ), __( 'Johnny Cache', 'johnny-cache' ),
			'manage_options', 'johnny-cache', array( $this, 'admin' ) );
		add_action( "load-$hook", array( $this, 'load' ) );
	}
    
	/**
	 * Main admin page load routine
	 * 
	 */
	function load() {
		if ( isset( $_GET['cache_group'] ) && ! empty( $_GET['cache_group'] ) ) {
			$cleared = $this->flush_group( $_GET['cache_group'] );
			$url = add_query_arg( 'keys_cleared', $cleared, menu_page_url( 'johnny-cache', false ) );
			$url = add_query_arg( 'cache_cleared', $_GET['cache_group'], $url );
			wp_redirect( $url );
			exit();
		}

		wp_enqueue_style( 'johnny-cache', trailingslashit( WP_PLUGIN_URL ) . 'johnny-cache/johnny-cache.css' );
		wp_enqueue_script( 'johnny-cache', trailingslashit( WP_PLUGIN_URL ) . 'johnny-cache/johnny-cache.js', '', $_SERVER['REQUEST_TIME'] );
	}
    
	/**
	 * Delete all items belong to a group in the cache
	 * 
	 * @global WP_Object_Cache $wp_object_cache
	 * @param string $group
	 * @return int
	 */
	function flush_group( $group ) {
		global $wp_object_cache;
		$cleared = 0;
		foreach ( $wp_object_cache->mc as $name => $instance ) {
			$servers = $wp_object_cache->mc[$name]->getStats();
			foreach ( $servers as $server => $stats ) {
				list( $ip, $port ) = explode( ':', $server );
				$list = $this->retrieve_keys( $ip, empty( $port ) ? 11211 : $port );
				foreach ( $list as $item ) {
					if ( strstr( $item, $group . ':' ) ) {
						$wp_object_cache->mc[$name]->delete( $item );
						$cleared++;
					}
				}
			}
		}
		return $cleared;
	}
    
	/**
	 * Retrieve all cache keys for an instance
	 * 
	 * @param string $server
	 * @param int $port
	 * @return array
	 */
	function retrieve_keys( $server, $port = 11211 ) {
		$memcache = new Memcache();
		$memcache->connect( $server, $port );
		$list = array();
		$allSlabs = $memcache->getExtendedStats( 'slabs' );
		$items = $memcache->getExtendedStats( 'items' );
		foreach ( $allSlabs as $server => $slabs ) {
			foreach( $slabs as $slabId => $slabMeta ) {
				if ( ! empty( $slabId ) ) {
					$cdump = $memcache->getExtendedStats( 'cachedump', (int) $slabId );
					foreach( $cdump as $keys => $arrVal ) {
						if ( !is_array( $arrVal ) ) continue;
						foreach( $arrVal as $k => $v ) {                   
							$list[] = $k;
						}
					}                    
				}
			}
		} 
		return $list;
	}
    
	/**
	 * Output HTML for a cached item's value
	 * 
	 * @param string $key
	 * @param string $group
	 */
	function do_item( $key, $group ) {
		$value = wp_cache_get( $key, $group );
		if ( is_array( $value ) ) {
			ob_start();
			print_r( $value );
			$value = ob_get_clean();
		} elseif ( is_object( $value ) ) {
			$value = serialize( $value );
		}

		printf( '<textarea class="widefat" rows="10" cols="35">%s</textarea>', esc_html( $value ) );
	}

	/**
	 * Output HTML table representation of a cache instance
	 * 
	 * @global WP_Object_Cache $wp_object_cache
	 * @param string $server
	 */
	function do_instance( $server ) {
		global $wp_object_cache;
		$flush_group_nonce = wp_create_nonce( $this->flush_group_nonce );
		$remove_item_nonce = wp_create_nonce( $this->remove_item_nonce );
		$get_item_nonce = wp_create_nonce( $this->get_item_nonce );

		$blog_id = 0;
		$list = $this->retrieve_keys( $server );

		if ( is_multisite() ):
			$keymaps = array(); ?>
		<table borderspacing="0" id="cache-<?php echo sanitize_title( $server ) ?>">      
			<tr><th>Blog ID</th><th>Cache Group</th><th>Keys</th></tr>
		<?php 
			foreach ( $list as $item ) {
				$parts = explode( ':', $item );
				if ( is_numeric( $parts[0] ) ) {
					$blog_id = array_shift( $parts );
					$group = array_shift( $parts );
				} else {
					$group = array_shift( $parts );
					$blog_id = 0;
				}

				if ( count( $parts ) > 1 ) {
					$key = join( ':', $parts );
				} else {
					$key = $parts[0];
				}
				$group_key = $blog_id . $group;
				if ( isset( $keymaps[$group_key] ) ) {
					$keymaps[$group_key][2][] = $key;                            
				} else {
					$keymaps[$group_key] = array( $blog_id, $group, array( $key ) );                            
				}  
			}
			ksort( $keymaps );
			foreach ( $keymaps as $group => $values ) { 
				list( $blog_id, $group, $keys ) = $values;

				$group_link = empty( $group ) ? '' : sprintf( 
					'%s<p><a class="button jc-flush-group" href="/wp-admin/admin-ajax.php?action=jc-flush-group&blog_id=%d&group=%s&nonce=%s">Flush Group</a></p>',
					$group, $blog_id, $group, $flush_group_nonce    
				);

				$key_links = array();
				foreach ( $keys as $key ) {
					$fmt = '<p data-key="%1$s">%1$s ' .
						'<a class="jc-remove-item" href="/wp-admin/admin-ajax.php?action=jc-remove-item&key=%1$s&blog_id=%2$d&group=%3$s&nonce=%4$s">Remove</a>' . 
						' <a class="jc-view-item" href="/wp-admin/admin-ajax.php?action=jc-get-item&key=%1$s&blog_id=%2$d&group=%3$s&nonce=%5$s">View Contents</a>' .     
					'</p>';
					$key_links[] = sprintf( 
						$fmt,
						$key, $blog_id, $group, $remove_item_nonce, $get_item_nonce
					);
				}

				printf( 
					'<tr><td class="td-blog-id">%d</td><td class="td-group">%s</td><td>%s</td></tr>', 
					$blog_id, 
					$group_link, 
					join( '', array_values( $key_links ) ) 
				);
			}
		?>
		</table>    
	<?php endif;
	}
    
	/**
	 * HTML for main admin page
	 * 
	 * @global WP_Object_Cache $wp_object_cache
	 */
	function admin() {
		global $wp_object_cache;
		$get_instance_nonce = wp_create_nonce( $this->get_instance_nonce );
	?>
	<div class="wrap johnny-cache" id="jc-wrapper">
		<h2>Johnny Cache</h2>

		<?php 
		if ( isset( $_GET['cache_cleared'] ) ) {
			printf( 
				'<p><strong>%s</strong>! Cleared <strong>%d</strong> keys from the cache group: %s</p>', 
				__( 'DONE' ),
				isset( $_GET['keys_cleared'] ) ? (int) $_GET['keys_cleared'] : 0,
				isset( $_GET['cache_cleared'] ) ? $_GET['cache_cleared'] : 'none returned'   
			);
		}
		?>

		<form action="<?php menu_page_url( 'johnny-cache' ) ?>">
			<p>Clear Cache Group:</p>
			<input type="hidden" name="page" value="johnny-cache" />
			<input type="text" name="cache_group" />
			<button>Clear</button>
		</form>

		<?php 
		if ( isset( $_GET['userid'] ) ) {
			$user = get_user_by( 'id', $_GET['userid'] );
			wp_cache_delete( $_GET['userid'], 'users' );
			wp_cache_delete( $user->user_login, 'userlogins' );
			wp_cache_delete( $_GET['userid'], 'user_meta' );
			$user = get_user_by( 'id', $_GET['userid'] );
			print_r( (array) $user );
		}
		?>
		<form>
			<p>Enter a User ID:</p>
			<input type="hidden" name="page" value="johnny-cache"/>
			<input type="text" name="userid" />
			<button>Clear Cache for User</button>
		</form>

		<select id="instance-selector" data-nonce="<?php echo $get_instance_nonce ?>">
			<option value="">Select a Memcached instance</option>
		<?php foreach ( $wp_object_cache->mc as $name => $instance ): ?>
			<optgroup label="<?php echo $name ?>">
		<?php    
			$servers = $wp_object_cache->mc[$name]->getStats();
			foreach ( $servers as $server => $stats ): 
				list( $ip, $port ) = explode( ':', $server ); ?>
				<option value="<?php echo $ip ?>"><?php echo $ip ?></option>    
			<?php endforeach ?>
			</optgroup>
		<?php endforeach ?>    
		</select>    
		<a class="button" id="refresh-instance">Refresh</a>
		<div id="debug"></div>
		<div id="instance-store"></div>
	</div><?php  
	}
}
JohnnyCache::get_instance();

/**
 * Public function for JohnnyCache::flush_group
 * 
 * @param string $group
 */
function wp_cache_flush_group( $group ) {
	JohnnyCache::get_instance()->flush_group( $group );
}
