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
    var $get_instance_nonce = 'jc-get_instance';
    var $remove_item_nonce = 'jc-remove_item';
    var $flush_group_nonce = 'jc-flush_group';
    var $get_item_nonce = 'jc-get_item';
    
    function init() {
        add_action( 'admin_menu',               array( $this, 'page' ) );
        add_action( 'wp_ajax_jc-flush-group',   array( $this, 'flush_group' ) );
        add_action( 'wp_ajax_jc-remove-item',   array( $this, 'remove_item' ) );
        add_action( 'wp_ajax_jc-get-instance',  array( $this, 'get_instance' ) );
        add_action( 'wp_ajax_jc-get-item',  array( $this, 'get_item' ) );
    }
    
    function get_instance() {
        check_ajax_referer( $this->get_instance_nonce, 'nonce' );
        extract( $_REQUEST, EXTR_SKIP );
        
        nocache_headers();
        
        $this->do_instance( $name );
        exit();
    }
    
    function get_item() {
        check_ajax_referer( $this->get_item_nonce, 'nonce' );
        extract( $_REQUEST, EXTR_SKIP );
        
        nocache_headers();
        
        $this->do_item( $key, $group );
        exit();
    }
        
    function flush_group() {
        check_ajax_referer( $this->flush_group_nonce, 'nonce' );
        extract( $_REQUEST, EXTR_SKIP );
        
        nocache_headers();
        
        foreach ( $keys as $key ) {
            wp_cache_delete( $key, $group );
        }
        exit();
    }
    
    function remove_item() {
        check_ajax_referer( $this->remove_item_nonce, 'nonce' );
        extract( $_REQUEST, EXTR_SKIP );
        
        nocache_headers();
        
        wp_cache_delete( $key, $group );
        exit();
    }
    
    function page() {
        $hook = add_menu_page( __( 'Johnny Cache', 'johnny-cache' ), __( 'Johnny Cache', 'johnny-cache' ),
            'manage_options', 'johnny-cache', array( $this, 'admin' ) );
        add_action( "load-$hook", array( $this, 'load' ) );
    }
    
    function load() {
        wp_enqueue_style( 'johnny-cache', trailingslashit( WP_PLUGIN_URL ) . 'johnny-cache/johnny-cache.css' );
        wp_enqueue_script( 'johnny-cache', trailingslashit( WP_PLUGIN_URL ) . 'johnny-cache/johnny-cache.js', '', $_SERVER['REQUEST_TIME'] );
    }
    
    function do_item( $key, $group ) {
        $value = wp_cache_get( $key, $group );
        $value = is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
        printf( '<textarea class="widefat" rows="10" cols="35">%s</textarea>', esc_html( $value ) );
    }
    
    function do_instance( $server ) {
        global $wp_object_cache;
        $flush_group_nonce = wp_create_nonce( $this->flush_group_nonce );
        $remove_item_nonce = wp_create_nonce( $this->remove_item_nonce );
        $get_item_nonce = wp_create_nonce( $this->get_item_nonce );
        
        $blog_id = 0;
        $memcache = new Memcache();
        $memcache->connect( $server, '11211' );
        $list = array();
        $allSlabs = $memcache->getExtendedStats( 'slabs' );
        $items = $memcache->getExtendedStats( 'items' );
        foreach ( $allSlabs as $server => $slabs ) {
            foreach( $slabs as $slabId => $slabMeta ) {
                $cdump = $memcache->getExtendedStats( 'cachedump', (int) $slabId );
                foreach( $cdump as $keys => $arrVal ) {
                    if ( !is_array( $arrVal ) ) continue;
                    foreach( $arrVal as $k => $v ) {                   
                        $list[] = $k;
                    }
               }
            }
        } 

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
                    $blog_id = -1;
                }

                if ( $blog_id === -1 ) {
                    $blog_id = 0;
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
                } else {
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
    
    function admin() {
        global $wp_object_cache;
        $get_instance_nonce = wp_create_nonce( $this->get_instance_nonce );
    ?>
    <div class="wrap johnny-cache" id="jc-wrapper">
        <h2>Johnny Cache</h2>
        <?php 
        if ( isset( $_GET['userid'] ) ) {
            $user = get_user_by( 'id', $_GET['userid'] );
            wp_cache_delete( $_GET['userid'], 'users' );
            wp_cache_delete( $user->user_login, 'userlogins' );
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
            $servers = $wp_object_cache->mc[$name]->getExtendedStats();
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
$_johnny_cache = new JohnnyCache();
$_johnny_cache->init();