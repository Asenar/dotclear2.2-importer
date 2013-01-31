<?php
/*
Plugin Name: DotClear2.2 Importer
Plugin URI: https://github.com/Asenar/dotclear2.2-importer
Description: Import categories, users, posts, comments, and links from a DotClear blog.
Author: Asenar
Author URI: 
Version: 0.4
Comment: forked from dotclear-importer 0.3 from kyon79
License: GPL v2
*/

/** default value (charset, checkbox default status, ...)  **/


/**
 * Database charset used for the dotclear database
 */
$dc_db_default_charset = 'UTF-8';

/******************/
if ( !defined('WP_LOAD_IMPORTERS') )
	return;

/*
	Add These Functions to make our lives easier
**/

if (!function_exists('get_comment_count')) {
	/**
	 * Get the comment count for posts.
	 *
	 * @package WordPress
	 * @subpackage Dotclear2_Import
	 *
	 * @param int $post_ID Post ID
	 * @return int
	 */
	function get_comment_count($post_ID)
	{
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare("SELECT count(*) FROM $wpdb->comments WHERE comment_post_ID = %d", $post_ID) );
	}
}

if (!function_exists('link_exists')) {
	/**
	 * Check whether link already exists.
	 *
	 * @package WordPress
	 * @subpackage Dotclear2_Import
	 *
	 * @param string $linkname
	 * @return int
	 */
	function link_exists($linkname)
	{
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare("SELECT link_id FROM $wpdb->links WHERE link_name = %s", $linkname) );
	}
}

/**
 * Convert from dotclear charset to utf8 if required
 *
 * @package WordPress
 * @subpackage Dotclear2_Import
 *
 * @param string $s
 * @return string
 */
function csc ($s) {
	if (seems_utf8 ($s)) {
		return $s;
	} else {
		return iconv(get_option ("dccharset"),"UTF-8",$s);
	}
}

/**
 * @package WordPress
 * @subpackage Dotclear2_Import
 *
 * @param string $s
 * @return string
 */
function textconv ($s) {
	return csc (preg_replace ('|(?<!<br />)\s*\n|', ' ', $s));
}

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * DotClear Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class Dotclear2_Import extends WP_Importer {

	function header()
	{
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import DotClear', 'dotclear2-importer').'</h2>';
		echo '<p>'.__('Steps may take a few minutes depending on the size of your database. Please be patient.', 'dotclear2-importer').'</p>';
	}

	function footer()
	{
		echo '</div>';
	}

	function greet()
	{
		echo '<div class="narrow"><p>'.__('Howdy! This importer allows you to extract posts from a DotClear database into your WordPress site.  Mileage may vary.', 'dotclear2-importer').'</p>';
		echo '<p>'.__('Your DotClear Configuration settings are as follows:', 'dotclear2-importer').'</p>';
		echo '<form action="admin.php?import=dotclear&amp;step=1" method="post">';
		wp_nonce_field('import-dotclear');
		$this->db_form();
		echo '<p class="submit"><input type="submit" name="submit" class="button" value="'.esc_attr__('Import Categories', 'dotclear2-importer').'" /></p>';
		echo '</form></div>';
	}

	function get_dc_cats()
	{
		global $wpdb;
		// General Housekeeping
		$dcdb = new wpdb(get_option('dcuser'), get_option('dcpass'), get_option('dcname'), get_option('dchost'));
		set_magic_quotes_runtime(0);
		$dbprefix = get_option('dcdbprefix');

		// Get Categories
		$dc_blog_id = get_option('dc_blog_id');
		if (!empty($dc_blog_id))
			$sql = 'SELECT * FROM '.$dbprefix.'category WHERE blog_id="'.$dc_blog_id.'"';
		else
			$sql = 'SELECT * FROM '.$dbprefix.'category';
		return $dcdb->get_results($sql, ARRAY_A);
	}

	function get_dc_users()
	{
		global $wpdb;
		// General Housekeeping
		$dcdb = new wpdb(get_option('dcuser'), get_option('dcpass'), get_option('dcname'), get_option('dchost'));
		set_magic_quotes_runtime(0);
		$dbprefix = get_option('dcdbprefix');

		$dc_blog_id = get_option('dc_blog_id');
		if (!empty($dc_blog_id))
		{
			$sql = 'SELECT * FROM '.$dbprefix.'user u 
				INNER JOIN '.$dbprefix.'permissions p 
					ON u.user_id=p.user_id
				WHERE 
					blog_id="'.$dc_blog_id.'"
				';
		}
		else
			$sql = 'SELECT * FROM '.$dbprefix.'user';
		// Get Users
		$users = $dcdb->get_results($sql, ARRAY_A);
		foreach ($users as &$user)
		{
			$user['permissions'] = trim($user['permissions'], '|');
			$perms = preg_split('#'.preg_quote('|').'#', $user['permissions']);
			if (in_array('admin', $perms)
			)
				$user['user_level'] = 9;
			else
			{
				// @TODO I'm a lazy person, so everyone else at level 0
				$user['user_level'] = 0;
			}
		}
		return $users;
	}

	function get_dc_posts()
	{
		// General Housekeeping
		$dcdb = new wpdb(get_option('dcuser'), get_option('dcpass'), get_option('dcname'), get_option('dchost'));
		set_magic_quotes_runtime(0);
		$dbprefix = get_option('dcdbprefix');

		// Get Posts
		// @TODO pray to have all the correct user imported
		// @TODO add something to preserve current email over old email
		$dc_blog_id = get_option('dc_blog_id');
		if (!empty($dc_blog_id))
		{
			$sql = 'SELECT '.$dbprefix.'post.*, '.$dbprefix.'category.cat_title AS post_cat_name
				FROM '.$dbprefix.'post LEFT JOIN '.$dbprefix.'category
				ON '.$dbprefix.'post.cat_id = '.$dbprefix.'category.cat_id
				WHERE '.$dbprefix.'post.blog_id="'.$dc_blog_id.'" ';
		}
		else
		{
			$sql = 'SELECT '.$dbprefix.'post.*, '.$dbprefix.'category.cat_title AS post_cat_name
				FROM '.$dbprefix.'post LEFT JOIN '.$dbprefix.'category
				ON '.$dbprefix.'post.cat_id = '.$dbprefix.'category.cat_id
				WHERE 1 ';
		}
		$dc_post_active_only = get_option('dc_post_active_only');
		if ($dc_post_active_only)
			$sql .= ' AND post_status=1';

		return $dcdb->get_results($sql, ARRAY_A);
	}

	// @TODO : /!\ This is not tested
	function get_dc_comments()
	{
		global $wpdb;
		// General Housekeeping
		$dcdb = new wpdb(get_option('dcuser'), get_option('dcpass'), get_option('dcname'), get_option('dchost'));
		set_magic_quotes_runtime(0);
		$dbprefix = get_option('dcdbprefix');

		// Get Comments
		return $dcdb->get_results('SELECT * FROM '.$dbprefix.'comment', ARRAY_A);
	}

	function get_dc_links()
	{
		//General Housekeeping
		$dcdb = new wpdb(get_option('dcuser'), get_option('dcpass'), get_option('dcname'), get_option('dchost'));
		set_magic_quotes_runtime(0);
		$dbprefix = get_option('dcdbprefix');

		// @TODO not tested
		$dc_blog_id = get_option('dc_blog_id');
		if (!empty($dc_blog_id))
		{
			$sql = 'SELECT * FROM '.$dbprefix.'link
			WHERE blog_id="'.$dc_blog_id.'" ORDER BY link_position';
		}
		else
		{
			$sql = 'SELECT * FROM '.$dbprefix.'link ORDER BY link_position';
		}
		return $dcdb->get_results($sql, ARRAY_A);
	}

	function cat2wp($categories='')
	{
		// General Housekeeping
		global $wpdb;
		$count = 0;
		$dccat2wpcat = array();
		// Do the Magic
		if (is_array($categories)) {
			echo '<p>'.__('Importing Categories...', 'dotclear2-importer').'<br /><br /></p>';

			// Add Parent Categories first
			$parent_categories = array();
			foreach ($categories as $category) {
				// List Parent Categories
				extract($category);
				if(strpos($cat_url, '/') !== false) {
					$parent_categories[current(explode('/', $cat_url))] = true;
				}
			}
			foreach ($categories as $category) {
				// Add Parent Categories and keep their IDs
				extract($category);
				if(isset($parent_categories[$cat_url])) {
					$count++;
					$parent_categories[$cat_url] = $dccat2wpcat[$cat_id] = $this->addcat2wp($category);
				}
			}
			foreach ($categories as $k => $category) {
				// Associate Child Categories their Parent
				extract($category);
				if(strpos($cat_url, '/') !== false) {
					$categories[$k]['cat_parent'] = $parent_categories[current(explode('/', $cat_url))];
				}
			}
			// Add Child Categories and skip Parent Categories
			foreach ($categories as $category) {
				extract($category);
				if(!isset($parent_categories[$cat_url])) {
					$count++;
					$category['cat_url'] = str_replace('/', '-', $cat_url); // Slashes not accepted by WP
					$dccat2wpcat[$cat_id] = $this->addcat2wp($category);
				}
			}

			// Store category translation for future use
			add_option('dccat2wpcat',$dccat2wpcat);
			echo '<p>'.sprintf(_n('Done! <strong>%1$s</strong> category imported.', 'Done! <strong>%1$s</strong> categories imported.', $count, 'dotclear2-importer'), $count).'<br /><br /></p>';
			return true;
		}
		echo __('No Categories to Import!', 'dotclear2-importer');
		return false;
	}

	function addcat2wp($category) {
		global $wpdb;
		extract($category);

		// Make Nice Variables
		$name = $wpdb->escape($cat_url);
		$title = $wpdb->escape(csc ($cat_title));
		$desc = $wpdb->escape(csc ($cat_desc));
		$parent = isset($cat_parent) ? $cat_parent : 0;

		if ($cinfo = category_exists($name)) {
			$ret_id = wp_insert_category(array('cat_ID' => $cinfo, 'category_nicename' => $name, 'cat_name' => $title, 'category_description' => $desc, 'category_parent' => $parent));
		} else {
			$ret_id = wp_insert_category(array('category_nicename' => $name, 'cat_name' => $title, 'category_description' => $desc, 'category_parent' => $parent));
		}

		return $ret_id;
	}

	function users2wp($users='') {
		// General Housekeeping
		global $wpdb;
		$count = 0;
		$dcid2wpid = array();

		// Midnight Mojo
		if (is_array($users)) {
			echo '<div>'.__('Importing Users...', 'dotclear2-importer').'<br /><br /></div>';
			foreach ($users as $user) {
				$count++;
				extract($user);
				// Make Nice Variables
				$user_name = $wpdb->escape(csc ($user_name));
				$realname = $wpdb->escape(csc ($user_displayname));
				// look first for user_id, then user_email, then name to match current wordpress user)
				if (!$uinfo = get_user_by('login', $user_id))
					if (!$uinfo = get_user_by('email', $user_email))
						if (!$uinfo = get_user_by('login', $user_name))
						{
							echo "user not found"; // oO, how should I rely author <> post ? admin ?
							$new_user = true;
						}
						else
							echo "user found by dotclear user_name"; // delme later
					else
						echo "user found by dotclear user_email"; // delme later
				else
					echo '<div class="highlight">user found by dotclear user_id</div>';
				 
				 if ($uinfo) {

					$user_exists = true;
					$ret_id = wp_insert_user(array(
								'ID'		=> $uinfo->ID,
								'user_login'	=> $user_id,
								'user_nicename'	=> $realname,
								'user_email'	=> $user_email,
								'user_url'	=> 'http://',
								'display_name'	=> $realname)
								);
				} else {
					$user_exists = false;
					$ret_id = wp_insert_user(array(
								'user_login'	=> $user_id,
								'user_nicename'	=> csc ($user_displayname),
								'user_email'	=> $user_email,
								'user_url'	=> 'http://',
								'display_name'	=> $realname)
								);
				}
				if(is_a($ret_id, 'WP_Error')) {
					// I don't want to stop just for one wrong user already existing or something.
					// but I will be noticed anyway :)
					if (isset($ret_id->errors['existing_user_login']))
						// User already exists, 
						echo '<div class="error">'.sprintf(__('Error! User <strong>%s</strong> already exists. You may prefer to reinstall a fresh copy of Wordpress with another first user name.', 'dotclear2-importer'), $user_id).'<br /><br /></div>';
					else
						// @TODO we should display the correct error message instead of that 
						echo '<div class="error">'.sprintf(__('Error during insertion / update user <strong>$s</strong>! You may prefer to reinstall a fresh copy of Wordpress with another first user name.', 'dotclear2-importer'), $user_id).'<br /><br /></div>';
						continue;
				}

				$dcid2wpid[$user_id] = $ret_id;

				// Set DotClear-to-WordPress permissions translation
				// Update Usermeta Data
				$user = new WP_User($ret_id);
				$wp_perms = $user_level + 1;
				if (10 == $wp_perms) { $user->set_role('administrator'); }
				else if (9  == $wp_perms) { $user->set_role('editor'); }
				else if (5  <= $wp_perms) { $user->set_role('editor'); }
				else if (4  <= $wp_perms) { $user->set_role('author'); }
				else if (3  <= $wp_perms) { $user->set_role('contributor'); }
				else if (2  <= $wp_perms) { $user->set_role('contributor'); }
				else                     { $user->set_role('subscriber'); }

				update_user_meta( $ret_id, 'wp_user_level', $wp_perms);
				update_user_meta( $ret_id, 'rich_editing', 'false');
				update_user_meta( $ret_id, 'first_name', csc ($user_firstname));
				update_user_meta( $ret_id, 'last_name', csc ($user_name));
				// and also noticed for a success import 
				if ($user_exists)
					echo '<div class="highlight">'.sprintf(__('%s (email %s) succesfully updated'), $user_name, $user_email).'</div>';
				else
					echo '<div class="highlight">'.sprintf(__('%s (email %s) succesfully imported'), $user_name, $user_email).'</div>';

			}// End foreach($users as $user)

			// Store id translation array for future use
			add_option('dcid2wpid',$dcid2wpid);


			echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> users imported.', 'dotclear2-importer'), $count).'<br /><br /></p>';
			return true;
		}// End if(is_array($users)

		echo __('No Users to Import!', 'dotclear2-importer');
		return false;

	}// End function user2wp()

	function posts2wp($posts='') {
		// General Housekeeping
		global $wpdb;
		$count = 0;
		$dcposts2wpposts = array();
		$cats = array();

		// Do the Magic
		if (is_array($posts)) {
			echo '<p>'.__('Importing Posts...', 'dotclear2-importer').'<br /><br /></p>';
			foreach($posts as $post)
			{
				$count++;
				extract($post);

				// Set DotClear-to-WordPress status translation
				$stattrans = array('-2' => 'draft', 0 => 'draft', 1 => 'publish');
				$comment_status_map = array (0 => 'closed', 1 => 'open');

				//Can we do this more efficiently?
				$dc_user_replace = get_option('dc_user_replace');
				if ($dc_user_replace) {
					$uinfo = ( get_user_by('login', $dc_user_replace ) ) ? get_user_by('login', $dc_user_replace ) : 1;
				}
				else
					$uinfo = ( get_user_by('login', $user_id ) ) ? get_user_by('login', $user_id ) : 1;
				$authorid = ( is_object( $uinfo ) ) ? $uinfo->ID : $uinfo ;

				$Title = $wpdb->escape(csc ($post_title));
				$post_content = textconv ($post_content);
				if ($post_excerpt != "") {
					$post_excerpt = textconv ($post_excerpt);
					$post_content = $post_excerpt ."\n<!--more-->\n".$post_content;
				}
				$post_excerpt = $wpdb->escape ($post_excerpt);
				$post_content = $wpdb->escape ($post_content);
				$post_status = $stattrans[$post_status];

				// Import Post data into WordPress
				// f** not found items
				if (empty($post_modified_gmt))
					$post_modified_gmt = '';
				if (empty($post_nb_trackback))
					$post_nb_trackback = '';
				if (empty($post_nb_comment))
					$post_nb_comment = '';
				if ($pinfo = post_exists($Title,$post_content)) {
					$ret_id = wp_insert_post(array(
							'ID'			=> $pinfo,
							'post_author'		=> $authorid,
							'post_date'		=> $post_dt,
							'post_date_gmt'		=> $post_dt,
							'post_modified'		=> $post_upddt,
							'post_modified_gmt'	=> $post_upddt,
							'post_title'		=> $Title,
							'post_content'		=> $post_content,
							'post_excerpt'		=> $post_excerpt,
							'post_status'		=> $post_status,
							'post_name'		=> $post_url, //$post_titre_url,
							'comment_status'	=> $comment_status_map[$post_open_comment],
							'ping_status'		=> $comment_status_map[$post_open_tb],
							'comment_count'		=> $post_nb_comment + $post_nb_trackback)
							);
					if ( is_wp_error( $ret_id ) )
						return $ret_id;
				} else {
					$ret_id = wp_insert_post(array(
							'post_author'		=> $authorid,
							'post_date'		=> $post_dt,
							'post_date_gmt'		=> $post_dt,
							'post_modified'		=> $post_modified_gmt,
							'post_modified_gmt'	=> $post_modified_gmt,
							'post_title'		=> $Title,
							'post_content'		=> $post_content,
							'post_excerpt'		=> $post_excerpt,
							'post_status'		=> $post_status,
							'post_name'		=> $post_url, //$post_titre_url,
							'comment_status'	=> $comment_status_map[$post_open_comment],
							'ping_status'		=> $comment_status_map[$post_open_tb],
							'comment_count'		=> $post_nb_comment + $post_nb_trackback)
							);
					if ( is_wp_error( $ret_id ) )
						return $ret_id;
				}
				$dcposts2wpposts[$post_id] = $ret_id;

				// Make Post-to-Category associations
				$cats = array();
				$category1 = get_category_by_slug($post_cat_name);
				if ($category1)
					$category1 = $category1->term_id;

				if ($cat1 = $category1) { $cats[1] = $cat1; }

				if (!empty($cats)) { wp_set_post_categories($ret_id, $cats); }
			}
		}
		// Store ID translation for later use
		add_option('dcposts2wpposts',$dcposts2wpposts);

		echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> posts imported.', 'dotclear2-importer'), $count).'<br /><br /></p>';
		return true;
	}

	function comments2wp($comments='') {
		// General Housekeeping
		global $wpdb;
		$count = 0;
		$dccm2wpcm = array();
		$postarr = get_option('dcposts2wpposts');

		// Magic Mojo
		if (is_array($comments)) {
			echo '<p>'.__('Importing Comments...', 'dotclear2-importer').'<br /><br /></p>';
			foreach ($comments as $comment) {
				$count++;
				extract($comment);

				// WordPressify Data
				$comment_ID = (int) ltrim($comment_id, '0');
				$comment_post_ID = (int) $postarr[$post_id];
				$comment_approved = $comment_status;
				$name = $wpdb->escape(csc ($comment_author));
				$email = $wpdb->escape($comment_email);
				$web = "http://".$wpdb->escape($comment_site);
				$message = $wpdb->escape(textconv ($comment_content));

				$comment = array(
							'comment_post_ID'	=> $comment_post_ID,
							'comment_author'	=> $name,
							'comment_author_email'	=> $email,
							'comment_author_url'	=> $web,
							'comment_author_IP'	=> $comment_ip,
							'comment_date'		=> $comment_dt,
							'comment_date_gmt'	=> $comment_dt,
							'comment_content'	=> $message,
							'comment_approved'	=> $comment_approved);
				$comment = wp_filter_comment($comment);

				if ( $cinfo = comment_exists($name, $comment_dt) ) {
					// Update comments
					$comment['comment_ID'] = $cinfo;
					$ret_id = wp_update_comment($comment);
				} else {
					// Insert comments
					$ret_id = wp_insert_comment($comment);
				}
				$dccm2wpcm[$comment_ID] = $ret_id;
			}
			// Store Comment ID translation for future use
			add_option('dccm2wpcm', $dccm2wpcm);

			// Associate newly formed categories with posts
			get_comment_count($ret_id);


			echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> comments imported.', 'dotclear2-importer'), $count).'<br /><br /></p>';
			return true;
		}
		echo __('No Comments to Import!', 'dotclear2-importer');
		return false;
	}

	function links2wp($links='') {
		// General Housekeeping
		global $wpdb;
		$count = 0;

		// Deal with the links
		if (is_array($links)) {
			echo '<p>'.__('Importing Links...', 'dotclear2-importer').'<br /><br /></p>';
			foreach ($links as $link) {
				$count++;
				extract($link);

				if ($title != "") {
					if ($cinfo = term_exists(csc ($title), 'link_category')) {
						$category = $cinfo['term_id'];
					} else {
						$category = wp_insert_term($wpdb->escape (csc ($title)), 'link_category');
						$category = $category['term_id'];
					}
				} else {
					$linkname = $wpdb->escape(csc ($label));
					$description = $wpdb->escape(csc ($title));

					if ($linfo = link_exists($linkname)) {
						$ret_id = wp_insert_link(array(
									'link_id'		=> $linfo,
									'link_url'		=> $href,
									'link_name'		=> $linkname,
									'link_category'		=> $category,
									'link_description'	=> $description)
									);
					} else {
						$ret_id = wp_insert_link(array(
									'link_url'		=> $url,
									'link_name'		=> $linkname,
									'link_category'		=> $category,
									'link_description'	=> $description)
									);
					}
					$dclinks2wplinks[$link_id] = $ret_id;
				}
			}
			add_option('dclinks2wplinks',$dclinks2wplinks);
			echo '<p>';
			printf(_n('Done! <strong>%s</strong> link or link category imported.', 'Done! <strong>%s</strong> links or link categories imported.', $count, 'dotclear2-importer'), $count);
			echo '<br /><br /></p>';
			return true;
		}
		echo __('No Links to Import!', 'dotclear2-importer');
		return false;
	}

	function import_categories() {
		// Category Import
		$cats = $this->get_dc_cats();
		$this->cat2wp($cats);
		add_option('dc_cats', $cats);



		echo '<form action="admin.php?import=dotclear&amp;step=2" method="post">';
		wp_nonce_field('import-dotclear');
		printf('<p>
			<label for="dc_skip_users">%s</label>
			<input type="checkbox" name="dc_skip_users" value="1" id="dc_skip_users" /></p>', __('Skip users:', 'dotclear2-importer'));
		printf('<p>
			<label for="dc_user_replace">%s</label>
			<input type="text" name="dc_user_replace" value="admin" id="dc_user_replace" /></p>',
			__('If this is enabled, I want to use the default author :', 'dotclear2-importer'));
		printf('<p class="submit"><input type="submit" name="submit" class="button" value="%s" /></p>', esc_attr__('Import Users', 'dotclear2-importer'));
		echo '</form>';

	}

	function import_users() {
		if (!empty($_POST['dc_skip_users'])) {
			if (get_option('dc_skip_users'))
				delete_option('dc_skip_users');
			add_option('dc_skip_users', sanitize_user($_POST['dc_skip_users'], true));
			if (get_option('dc_user_replace'))
				delete_option('dc_user_replace');
			add_option('dc_user_replace', sanitize_user($_POST['dc_user_replace'], true));
		}
		// User Import
		$dc_skip_users = get_option('dc_skip_users');
		if (!$dc_skip_users) {
			$users = $this->get_dc_users();
			$this->users2wp($users);
		}
		else echo __('User importation has been skipped');

		echo '<form action="admin.php?import=dotclear&amp;step=3" method="post">';
		wp_nonce_field('import-dotclear');
		printf('<p>
		<label for="dc_post_active_only">%s</label>
		<input type="checkbox" name="dc_post_active_only" value="1" id="dc_post_active_only" /></p>', __('Import only active post:', 'dotclear2-importer'));
		printf('<p class="submit"><input type="submit" name="submit" class="button" value="%s" /></p>', esc_attr__('Import Posts', 'dotclear2-importer'));
		echo '</form>';
	}

	function import_posts() {
		if (!empty($_POST['dc_post_active_only'])) {
			if (get_option('dc_post_active_only'))
				delete_option('dc_post_active_only');
			add_option('dc_post_active_only', sanitize_user($_POST['dc_post_active_only'], true));
		}
		// Post Import
		$posts = $this->get_dc_posts();
		$result = $this->posts2wp($posts);
		if ( is_wp_error( $result ) )
			return $result;

		echo '<form action="admin.php?import=dotclear&amp;step=4" method="post">';
		wp_nonce_field('import-dotclear');
		printf('<p>
		<label for="dc_skip_comments">%s</label>
		<input type="checkbox" name="dc_skip_comments" value="1" id="dc_skip_comments" /></p>', __('Skip comments:', 'dotclear2-importer'));
		printf('<p class="submit"><input type="submit" name="submit" class="button" value="%s" /></p>', esc_attr__('Import Comments', 'dotclear2-importer'));
		echo '</form>';
	}

	function import_comments() {
		if (!empty($_POST['dc_skip_comments'])) {
			if (get_option('dc_skip_comments'))
				delete_option('dc_skip_comments');
			add_option('dc_skip_comments', sanitize_user($_POST['dc_skip_comments'], true));
		}

		$dc_skip_comments = get_option('dc_skip_comments');
		// NOTE : this is not tested
		if (!$dc_skip_comments)
		{
			// Comment Import
			$comments = $this->get_dc_comments();
			$this->comments2wp($comments);
		}
		echo '<form action="admin.php?import=dotclear&amp;step=5" method="post">';
		wp_nonce_field('import-dotclear');
		printf('<p>
		<label for="dc_skip_links">%s</label>
		<input type="checkbox" name="dc_skip_links" value="1" id="dc_skip_links" /></p>', __('Skip links:', 'dotclear2-importer'));
		printf('<p class="submit"><input type="submit" name="submit" class="button" value="%s" /></p>', esc_attr__('Import Links', 'dotclear2-importer'));
		echo '</form>';
	}

	function import_links()
	{
		if (!empty($_POST['dc_skip_links'])) {
			if (get_option('dc_skip_links'))
				delete_option('dc_skip_links');
				add_option('dc_skip_links', sanitize_user($_POST['dc_skip_links'], true));
		}

		$dc_skip_links = get_option('dc_skip_links');
		//Link Import
		// @TODO Really, I don't care about the old links
		if (!$dc_skip_links)
		{
			$links = $this->get_dc_links();
			$this->links2wp($links);
			add_option('dc_links', $links);
		}
		echo '<form action="admin.php?import=dotclear&amp;step=6" method="post">';
		wp_nonce_field('import-dotclear');
		printf('<p class="submit"><input type="submit" name="submit" class="button" value="%s" /></p>', esc_attr__('Finish', 'dotclear2-importer'));
		echo '</form>';
	}

	function cleanup_dcimport() {
		delete_option('dcdbprefix');
		delete_option('dc_cats');
		delete_option('dcid2wpid');
		delete_option('dccat2wpcat');
		delete_option('dcposts2wpposts');
		delete_option('dccm2wpcm');
		delete_option('dclinks2wplinks');
		delete_option('dcuser');
		delete_option('dcpass');
		delete_option('dcname');
		delete_option('dchost');
		delete_option('dccharset');

		delete_option('dc_skip_blog_id');
		delete_option('dc_post_active_only');
		delete_option('dc_skip_comments');
		delete_option('dc_skip_links');

		do_action('import_done', 'dotclear');
		$this->tips();
	}

	function tips() {
		echo '<p>'.__('Welcome to WordPress.  We hope (and expect!) that you will find this platform incredibly rewarding!  As a new WordPress user coming from DotClear, there are some things that we would like to point out.  Hopefully, they will help your transition go as smoothly as possible.', 'dotclear2-importer').'</p>';
		echo '<h3>'.__('Users', 'dotclear2-importer').'</h3>';
		echo '<p>'.sprintf(__('You have already setup WordPress and have been assigned an administrative login and password.  Forget it.  You didn&#8217;t have that login in DotClear, why should you have it here?  Instead we have taken care to import all of your users into our system.  Unfortunately there is one downside.  Because both WordPress and DotClear uses a strong encryption hash with passwords, it is impossible to decrypt it and we are forced to assign temporary passwords to all your users.  <strong>Every user has the same username, but their passwords are reset to password123.</strong>  So <a href="%1$s">Log in</a> and change it.', 'dotclear2-importer'), '/wp-login.php').'</p>';
		echo '<h3>'.__('Preserving Authors', 'dotclear2-importer').'</h3>';
		echo '<p>'.__('Secondly, we have attempted to preserve post authors.  If you are the only author or contributor to your blog, then you are safe.  In most cases, we are successful in this preservation endeavor.  However, if we cannot ascertain the name of the writer due to discrepancies between database tables, we assign it to you, the administrative user.', 'dotclear2-importer').'</p>';
		echo '<h3>'.__('Textile', 'dotclear2-importer').'</h3>';
		echo '<p>'.__('Also, since you&#8217;re coming from DotClear, you probably have been using Textile to format your comments and posts.  If this is the case, we recommend downloading and installing <a href="http://www.huddledmasses.org/category/development/wordpress/textile/">Textile for WordPress</a>.  Trust me&#8230; You&#8217;ll want it.', 'dotclear2-importer').'</p>';
		echo '<h3>'.__('WordPress Resources', 'dotclear2-importer').'</h3>';
		echo '<p>'.__('Finally, there are numerous WordPress resources around the internet.  Some of them are:', 'dotclear2-importer').'</p>';
		echo '<ul>';
		echo '<li>'.__('<a href="http://wordpress.org/">The official WordPress site</a>', 'dotclear2-importer').'</li>';
		echo '<li>'.__('<a href="http://wordpress.org/support/">The WordPress support forums</a>', 'dotclear2-importer').'</li>';
		echo '<li>'.__('<a href="http://codex.wordpress.org/">The Codex (In other words, the WordPress Bible)</a>', 'dotclear2-importer').'</li>';
		echo '</ul>';
		echo '<p>'.sprintf(__('That&#8217;s it! What are you waiting for? Go <a href="%1$s">log in</a>!', 'dotclear2-importer'), '../wp-login.php').'</p>';
	}

	function db_form() {
		echo '<table class="form-table">';
		printf('<tr><th><label for="dc_blog_id">%s</label></th><td><input type="text" name="dc_blog_id" id="dc_blog_id" /></td></tr>', __('DotClear Blog Id:', 'dotclear2-importer'));
		printf('<tr><th><label for="dbuser">%s</label></th><td><input type="text" name="dbuser" id="dbuser" /></td></tr>', __('DotClear Database User:', 'dotclear2-importer'));
		printf('<tr><th><label for="dbpass">%s</label></th><td><input type="password" name="dbpass" id="dbpass" /></td></tr>', __('DotClear Database Password:', 'dotclear2-importer'));
		printf('<tr><th><label for="dbname">%s</label></th><td><input type="text" name="dbname" id="dbname" /></td></tr>', __('DotClear Database Name:', 'dotclear2-importer'));
		printf('<tr><th><label for="dbhost">%s</label></th><td><input type="text" name="dbhost" id="dbhost" value="localhost" /></td></tr>', __('DotClear Database Host:', 'dotclear2-importer'));
		printf('<tr><th><label for="dbprefix">%s</label></th><td><input type="text" name="dbprefix" id="dbprefix" value="dc_"/></td></tr>', __('DotClear Table prefix:', 'dotclear2-importer'));
		printf('<tr><th><label for="dccharset">%s</label></th><td><input type="text" name="dccharset" id="dccharset" value="'.$dc_db_default_charset.'"/></td></tr>', __('Originating character set:', 'dotclear2-importer'));
		echo '</table>';
	}

	function dispatch() {

		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];
		$this->header();

		if ( $step > 0 ) {
			check_admin_referer('import-dotclear');

			if (!empty($_POST['dbuser'])) {
				if(get_option('dcuser'))
					delete_option('dcuser');
				add_option('dcuser', sanitize_user($_POST['dbuser'], true));
			}
			if (!empty($_POST['dbpass'])) {
				if(get_option('dcpass'))
					delete_option('dcpass');
				add_option('dcpass', sanitize_user($_POST['dbpass'], true));
			}

			if (!empty($_POST['dbname'])) {
				if (get_option('dcname'))
					delete_option('dcname');
				add_option('dcname', sanitize_user($_POST['dbname'], true));
			}
			if (!empty($_POST['dbhost'])) {
				if(get_option('dchost'))
					delete_option('dchost');
				add_option('dchost', sanitize_user($_POST['dbhost'], true));
			}
			if (!empty($_POST['dccharset'])) {
				if (get_option('dccharset'))
					delete_option('dccharset');
				add_option('dccharset', sanitize_user($_POST['dccharset'], true));
			}
			if (!empty($_POST['dbprefix'])) {
				if (get_option('dcdbprefix'))
					delete_option('dcdbprefix');
				add_option('dcdbprefix', sanitize_user($_POST['dbprefix'], true));
			}

			if (!empty($_POST['dc_blog_id'])) {
				if (get_option('dc_blog_id'))
					delete_option('dc_blog_id');
				add_option('dc_blog_id', sanitize_user($_POST['dc_blog_id'], true));
			}

		}
		// this works for me 
		define('MYSQL_NEW_LINK', 1);
		switch ($step) {
			default:
			case 0 :
				$this->greet();
				break;
			case 1 :
				$this->import_categories();
				break;
			case 2 :
				$this->import_users();
				break;
			case 3 :
				$result = $this->import_posts();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
			case 4 :
				$this->import_comments();
				break;
			case 5 :
				$this->import_links();
				break;
			case 6 :
				$this->cleanup_dcimport();
				break;
		}

		$this->footer();
	}

	function Dotclear2_Import() {
		// Nothing.
	}
}

$dc_import = new Dotclear2_Import();

register_importer('dotclear', __('DotClear', 'dotclear2-importer'), __('Import categories, users, posts, comments, and links from a DotClear blog.', 'dotclear2-importer'), array ($dc_import, 'dispatch'));

} // class_exists( 'WP_Importer' )

function dotclear_importer_init() {
    load_plugin_textdomain( 'dotclear2-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'dotclear_importer_init' );

