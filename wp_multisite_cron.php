<?php
/*
Plugin Name: WordPress Multisite cron
Plugin URI: https://github.com/chibani/wp_multisite_cron
Description: Plugin to handle multisite cron
Author: LoicG
Author URI: http://blog.loicg.net/
 */

register_activation_hook(__FILE__, array('wp_multisite_cron','plugin_activation'));
add_action('init', array('wp_multisite_cron', 'init'));

class wp_multisite_cron{
	
	const LANG = 'wp_multisite_cron';
	const LANG_DIR = '/lang/';
	
	/**
	 * 
	 * Plugin activation (sets default parameters)
	 */
	public static function plugin_activation(){
    	if(!self::get_option('concurrent_crons'))
    		self::update_option('concurrent_crons', 100);
    }
    
	/**
	 * 
	 * The main 'loader'
	 */
	public static function init() {

		//Setup the translation
		load_plugin_textdomain(self::LANG, false, dirname(plugin_basename( __FILE__ ) ) . self::LANG_DIR);

		//The multisite cron action		
		add_action('wp_ajax_wp_multisite_cron_call', array('wp_multisite_cron','cron_call'));
		add_action('wp_ajax_nopriv_wp_multisite_cron_call', array('wp_multisite_cron','cron_call'));

    	// admin actions and hooks
        if (is_admin()) {
            self::admin_hooks();
        }
    }
    
    /**
     * 
     * The admin hooks
     */
    public static function admin_hooks(){
    	//Setting menu
    	add_action('admin_menu', array('wp_multisite_cron', 'admin_menu'));
    	if(is_network_admin() && self::is_ready()){
    		add_action('network_admin_menu', array('wp_multisite_cron', 'network_admin_menu'));
    	}
    }
    
    /**
     * 
     * Set up the admin menu(s)
     */
    public static function admin_menu(){
    	add_options_page("WordPress multisite cron, information page", "WP multisite cron", 'manage_options', 'wp_multisite_cron_settings', array('wp_multisite_cron', "admin_settings"));
    }
    
	/**
     * 
     * Set up the admin menu(s)
     */
    public static function network_admin_menu(){
    	add_submenu_page("settings.php","WordPress multisite cron, information page", "WP multisite cron", 'manage_options', 'wp_multisite_cron_settings', array('wp_multisite_cron', "admin_settings"));
    }
    
    
    /**
     * 
     * The admin settings page
     */
    public static function admin_settings(){
    	?>
		<div class="wrap">
    		<div id="icon-options-general" class="icon32">
				<br />
			</div>
    		<h2>WordPress multisite cron</h2>
    		
    		<?php if (isset($_POST['wp_multisite_cron']) && !empty($_POST['wp_multisite_cron'])) :
	
				if (isset($_POST['_wpnonce']) && wp_verify_nonce( $_POST['_wpnonce'], plugin_basename( __FILE__ ) )) :
		        	self::delete_option('concurrent_crons');
		        	
		        	foreach($_POST['wp_multisite_cron'] as $option_name=>$option_value){
		        		self::update_option($option_name, $option_value);
		        	}
	        	?>
		            <div id="setting-error-settings_updated" class="updated settings-error">
						<p>
							<strong><?php _e('Settings saved.')?></strong>
						</p>
					</div>
				<?php else: ?>
					<div id="message" class="error fade">
						<p><?php _e('Unable to update settings.',self::LANG)?></p>
					</div>
				<?php endif;?>
			<?php endif;?>
    		
    		
    		
			<?php if(!is_multisite()):?>
				<div class="tool-box error">
					<h3><?php _e('Multisite',self::LANG) ?></h3>
					<p><?php _e('Your WordPress installation is not configured as multisite',self::LANG) ?>.</p>
					<p><?php _e("Read the WordPress' documentation about multisite to learn how to set up multisite",self::LANG)?> : <a href="http://codex.wordpress.org/Create_A_Network" target="_blank"><?php _e('here',self::LANG)?></a></p>
				</div>
			<?php endif;?>
									
						
			<?php if(!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON ):?>
				<div class="tool-box error">
					<h3><?php _e('WordPress default cron system',self::LANG) ?></h3>
					<p><?php _e('You must disable the WordPress cron system, to use this extension',self::LANG) ?>.</p>
					<p><?php _e('Add the following code in your wp-config.php',self::LANG) ?> :</p>
					<p>
						<code>define('DISABLE_WP_CRON', true);</code>
					</p>
				</div>
			<?php endif;?>
			
			<form action="" method="post">
	    		<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label for="concurrent_crons"><?php _e('Concurrent crons',self::LANG) ?></label><br />
								<em><?php _e("Lower it if some of your sites' cron don't run",self::LANG)?></em>
							</th>
							<td>
								<input type="text" name="wp_multisite_cron[concurrent_crons]" id="concurrent_crons" value="<?php echo self::get_option('concurrent_crons') ?>" />
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
	            	<input class="button-primary" name="plugin_ok" value="<?php _e('Save settings',self::LANG) ?>" type="submit" />
	            </p>
	            <?php wp_nonce_field( plugin_basename( __FILE__ ), '_wpnonce' );?>
			</form>
			
			
			<?php if(self::is_ready()): ?>
				<div class="tool-box">
					<h3><?php _e('Set-up WordPress multisite cron',self::LANG) ?></h3>
					<p><?php _e('Add the following line in your crontab',self::LANG)?> :</p>
					<p>
						<code>*/15 *<?php echo "\t" ?>* * * www-data /usr/bin/wget -qO- <?php echo admin_url('admin-ajax.php','http').'?action=wp_multisite_cron_call' ?></code>
					</p>
				</div>
			<?php endif;?>
    	</div>
    		
    	<?php 
    }
    
    /**
     * 
     * Check wether the config is ok to run real crons
     */
    public static function is_ready(){
    	return (is_multisite() && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    }
    
    /**
     * 
     * The real cron
     */
    public static function cron_call(){
    	
    	//load the blogs id
    	$blogs = self::get_blogs_id();
    	if(count($blogs)){
    		$mh = curl_multi_init();
	    	
    		$length = self::get_option('concurrent_crons');
	    	$offset = 0;
	    	
	    	//Cut the 
	    	while($blogs_slice = array_slice($blogs,$offset,$length)){
    		
	    		$offset += $length;
	    		
		    	foreach($blogs_slice as &$blog_id){
		    		switch_to_blog($blog_id);
		    		$cron_url = site_url().'/wp-cron.php?doing_wp_cron';
	
		    		//Add the url to the stack
		    		$chs[$blog_id]=curl_init();
		    		curl_setopt($chs[$blog_id], CURLOPT_URL, $cron_url);
		    		curl_setopt($chs[$blog_id], CURLOPT_HEADER, 0);
		    		curl_multi_add_handle($mh, $chs[$blog_id]);
		    	}
	    	
		    	//Launch :)
		    	$running = null;
		    	do{
		    		curl_multi_exec($mh, $still_running);
		    	}while($still_running > 0);
		    	
		    	curl_multi_close($mh);
		    }
    	}
    	die();
    }
    
    /**
     * 
     * Get the blog_id for each active blog in the multisite
     * @return array
     */
    public static function get_blogs_id(){
    	global $wpdb;

    	//Get the blogs' ids for blogs that are public and active
    	$blogs = $wpdb->get_col('SELECT blog_id FROM '.$wpdb->blogs.' WHERE public=1 AND deleted=0');
    	return $blogs;
    }
    
	/**
	 * 
	 * Get a plugin's specific option
	 * @param string $option_name
	 */
    public static function get_option($option_name){
    	return get_option('wp_multisite_cron_'.$option_name);
    }
    
    /**
     * 
     * Set a plugin's specific option
     * @param unknown_type $option_name
     */
	public static function update_option($option_name,$option_value){
    	return update_option('wp_multisite_cron_'.$option_name,$option_value);
    }
    
	/**
     * 
     * Delete a plugin's specific option
     * @param string $option_name
     */
    public static function delete_option($option_name){
    	return delete_option('wp_multisite_cron_'.$option_name);
    }
    
    
	/**
	 * 
	 * get the plugin's path url
	 */
	public static function get_plugin_url(){
		return get_bloginfo('url') . '/' . PLUGINDIR . '/' . dirname(plugin_basename(__FILE__));
	}
}