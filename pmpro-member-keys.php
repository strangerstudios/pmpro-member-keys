<?php
/*
Plugin Name: Paid Memberships Pro - Member Keys
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-member-keys
Description: Generates member keys that can be appended to URLs to allow members to view content without being signed in.
Version: .1
Author: strangerstudios
Author URI: http://www.strangerstudios.com
Text Domain: pmpromk
*/

/*
	The Plan
	* Generate a member key for each member.
	- Save it in usermeta pmpromemberkey
	- Add notice to run a one time update after activation
	- Allow admins to see/edit member keys. Give them a button to randomize it.
	* If a ?memberkey= is set on a URL, look it up and show content as if you were logged in as the assocatied member
	- Hook in pmpro_has_membership_level and pmpro_has_membership_access
	* Allow admins to generate a key for a single post.
	- If ?postkey= is set on a URL, check it against the post keys for that post and allow access to that post if the key matches
*/

/*
	Utility Functions
*/
//add the memberkey to a url
function pmpromk_url($url, $user_id = NULL) {
	$key = get_user_meta($user_id, 'pmpro_member_key', true);
	
	return add_query_arg("memberkey", $key, $url);
}

/*
	Generate a member key if you try to get one through user meta.
	Note that even non-members get a member key. It just won't work.
*/
function pmpromk_get_user_metadata($value, $user_id, $meta_key, $single) {
	if($meta_key == 'pmpro_member_key') {
		//would not have gotten the meta from cache or DB yet, so let's do that
		$meta_cache = wp_cache_get($user_id, 'user_meta');
		if ( !$meta_cache ) {
			$meta_cache = update_meta_cache( 'user', array( $user_id ) );
			$meta_cache = $meta_cache[$user_id];
		}

		if(!empty($meta_cache[$meta_key])) {
			//use the existing meta value
			$value = $meta_cache[$meta_key];
		}
		else {
			//we need to find or generate one
			//first check if they have one from the pmpro-member-rss addon
			$value = get_user_meta($user_id, "pmpromrss_key", true);

			//if not, create one
			if(empty($value)) {
				$value = md5(time() . md5('member' . $user_id . 'key') . AUTH_KEY);

				remove_filter('get_user_metadata', 'pmpromk_get_user_metadata', 10, 4);
				update_user_meta($user_id, "pmpro_member_key", $value);
				add_filter('get_user_metadata', 'pmpromk_get_user_metadata', 10, 4);
			}
		}
	}

	return $value;
}
add_filter('get_user_metadata', 'pmpromk_get_user_metadata', 10, 4);

/*
	Check for a memberkey in the url and set a global to track the memberkey user
*/
function pmpromk_init() {
	if(!empty($_REQUEST['memberkey'])) {
		global $wpdb;
		$key = $_REQUEST['memberkey'];
		global $pmpromk_user_id;
		$pmpromk_user_id = $wpdb->get_var("SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'pmpro_member_key' AND meta_value = '" . esc_sql($key) . "' LIMIT 1");
	}
}
add_action('init', 'pmpromk_init', 1);

/*
	Filter the pmpro_hasMembershipLevel function if a memberkey is used
*/
function pmpmromk_pmpro_has_membership_level($haslevel, $user_id, $levels) {
	global $pmpromk_user_id;		
	
	if(empty($pmpromk_user_id))
		return $haslevel;
	
	//check if the member key user has this level
	remove_filter('pmpro_has_membership_level', 'pmpmromk_pmpro_has_membership_level');
	if(pmpro_hasMembershipLevel($levels, $pmpromk_user_id))
		$haslevel = true;
	add_filter('pmpro_has_membership_level', 'pmpmromk_pmpro_has_membership_level');

	return $haslevel;
}
add_filter('pmpro_has_membership_level', 'pmpmromk_pmpro_has_membership_level');

/*
	Filter the pmpro_has_member_access function.
 */
function pmpromk_pmpro_has_membership_access_filter($hasaccess, $mypost, $myuser, $post_membership_levels)
{
	global $pmpromk_user_id;		
	
	if(empty($pmpromk_user_id))
		return $hasaccess;
	
	//we need to see if the user has access
	$post_membership_levels_ids = array();
	if(!empty($post_membership_levels))
	{
		foreach($post_membership_levels as $level)
			$post_membership_levels_ids[] = $level->id;
	}
		
	if(pmpro_hasMembershipLevel($post_membership_levels_ids, $pmpromk_user_id))
		$hasaccess = true;
	
	return $hasaccess;
}
add_filter('pmpro_has_membership_access_filter', 'pmpromk_pmpro_has_membership_access_filter', 10, 4);

/*
	Add member key to memberslist CSV export
*/
//add the column
function pmpromk_pmpro_members_list_csv_extra_columns($columns) {
	$columns["memberkey"] = "pmpromk_pmpro_members_list_csv_memberkey";
	
	return $columns;
}
add_filter("pmpro_members_list_csv_extra_columns", "pmpromk_pmpro_members_list_csv_extra_columns", 10);

//call back to get the member key
function pmpromk_pmpro_members_list_csv_memberkey($user) {
	$memberkey = get_user_meta($user->ID, 'pmpro_member_key', true);

	return $memberkey;
}

/*
	Add member key to the edit user page
*/
function pmpromk_user_profile($user) {
?>
<h3><?php _e("Member Key", "pmpromk"); ?></h3>
<table class="form-table">
<tr>
	<th><label for="memberkey"><?php _e('Key', 'pmpromk');?></label></th>
	<td><?php echo $user->pmpro_member_key;?></td>
</tr>
</table>
<?php
}
add_action('show_user_profile', 'pmpromk_user_profile');
add_action('edit_user_profile', 'pmpromk_user_profile');

/*
	Function to add links to the plugin row meta
*/
function pmpromk_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-member-keys.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpromk' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmpromk_plugin_row_meta', 10, 2);