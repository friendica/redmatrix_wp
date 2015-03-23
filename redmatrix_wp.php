<?php
/*
Plugin Name: Redmatrix_wp : Crosspost to Red Matrix
Plugin URI: http://blog.duthied.com/2011/09/12/friendika-cross-poster-wordpress-plugin/
Description: This plugin allows you to cross post to your Red Matrix account. Extended by Mike Macgirvin from a Friendica cross-posting tool 
Version: 1.3
Author: Devlon Duthied
Author URI: http://blog.duthied.com
*/

/*  Copyright 2011 Devlon Duthie (email: duthied@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define("redmatrix_wp_path", WP_PLUGIN_URL . "/" . str_replace(basename( __FILE__), "", plugin_basename(__FILE__)));
define("redmatrix_wp_version", "1.2");
$plugin_dir = basename(dirname(__FILE__));
$plugin = plugin_basename(__FILE__); 

define("redmatrix_wp_acct_name", "redmatrix_wp_admin_options");

function redmatrix_wp_deactivate() {
	delete_option('redmatrix_wp_seed_location');
	delete_option('redmatrix_wp_acct_name');
	delete_option('redmatrix_wp_user_name');
	delete_option('redmatrix_wp_password');
}

function redmatrix_wp_get_seed_location() {
	return get_option('redmatrix_wp_seed_location');
}

function redmatrix_wp_get_acct_name() {
	return get_option('redmatrix_wp_acct_name');
}

function redmatrix_wp_get_channel_name() {
	return get_option('redmatrix_wp_channel_name');
}

function redmatrix_wp_get_password() {
	return get_option('redmatrix_wp_password');
}

function redmatrix_wp_post($post_id) {

	$post = get_post($post_id);
	
    if (isset($_POST['redmatrix'])) {
		update_post_meta($post_id, 'redmatrix', '1');
	} 

	// if meta has been set
	if (get_post_meta($post_id, "redmatrix", true) === '1') {

		$user_name = redmatrix_wp_get_acct_name();
		$password = redmatrix_wp_get_password();
		$seed_location = redmatrix_wp_get_seed_location();
		$channel = redmatrix_wp_get_channel_name();
		$backlink = get_option('redmatrix_wp_backlink');
		
		if ((isset($user_name)) && (isset($password)) && (isset($seed_location))) {
			// remove potential comments
			$message = preg_replace('/<!--(.*)-->/Uis', '', $post->post_content);

			// get any tags and make them hashtags
			$post_tags = get_the_tags($post_id);
			if ($post_tags) {
				foreach($post_tags as $tag) {
			    	$tag_string .= "#" . $tag->name . " "; 
			  	}
			}

			$message_id = site_url() . '/' . $post_id;

			if (isset($tag_string)) {
				$message .=  "<br />$tag_string";	
			}

			$cats = '';

			$terms = get_the_terms($post_id,'category');
			if($terms) {
				foreach($terms as $term) {
					if(strlen($cats))
						$cats .= ',';
					$cats .= htmlspecialchars_decode($term->name, ENT_COMPAT);
				}
			}

			$bbcode = xpost_to_html2bbcode($message);

			if($backlink)
				$bbcode .= "\n\n" . _('Source:') . ' ' . '[url]' . get_permalink($post_id) . '[/url]';
			
			$url = $seed_location . '/api/statuses/update';
			
			$headers = array('Authorization' => 'Basic '.base64_encode("$user_name:$password"));
			$body = array(
				'title'     => xpost_to_html2bbcode($post->post_title),
				'status'    => $bbcode,
				'source'    => 'WordPress', 
				'namespace' => 'wordpress',
				'remote_id' => $message_id,
				'permalink' => $post->guid
			);
			if($channel)
				$body['channel'] = $channel;
			if($cats)
				$body['category'] = $cats;

			// post:
			$request = new WP_Http;
			$result = $request->request($url , array( 'method' => 'POST', 'body' => $body, 'headers' => $headers));

		}
		
	}
}


function redmatrix_wp_delete_post($post_id) {

	$post = get_post($post_id);
	
	// if meta has been set
	if ((get_post_meta($post_id, "redmatrix", true) == '1') || (get_post_meta($post_id, "post_from_red", true) == '1')) {

		$user_name = redmatrix_wp_get_acct_name();
		$password = redmatrix_wp_get_password();
		$seed_location = redmatrix_wp_get_seed_location();
		$channel = redmatrix_wp_get_channel_name();
		
		if ((isset($user_name)) && (isset($password)) && (isset($seed_location))) {

			$message_id = site_url() . '/' . $post_id;
			$url = $seed_location . '/api/statuses/destroy';
			
			$headers = array('Authorization' => 'Basic '.base64_encode("$user_name:$password"));
			$body = array(
				'namespace' => 'wordpress',
				'remote_id' => $message_id,
			);
			if($channel)
				$body['channel'] = $channel;
			
			// post:
			$request = new WP_Http;
			$result = $request->request($url , array( 'method' => 'POST', 'body' => $body, 'headers' => $headers));

		}
		
	}
}

function redmatrix_wp_delete_comment($comment_id) {

	// The comment may already be destroyed so we can't query it or the parent post. That means
	// we have to make a network call for any deleted comment to see if it's registered on Red. 
	// We really need a "before_delete_comment" action in WP to make
	// this more efficient.

	$user_name = redmatrix_wp_get_acct_name();
	$password = redmatrix_wp_get_password();
	$seed_location = redmatrix_wp_get_seed_location();
	$channel = redmatrix_wp_get_channel_name();
		
	if ((isset($user_name)) && (isset($password)) && (isset($seed_location))) {

		$c = get_comment($comment_id, ARRAY_A);
		if(! $c)
			return;

		$message_id = site_url() . '/' . $c['comment_post_ID'] . '.' . $comment_id ;
		$url = $seed_location . '/api/statuses/destroy';
			
		$headers = array('Authorization' => 'Basic '.base64_encode("$user_name:$password"));
		$body = array(
			'namespace' => 'wordpress',
			'comment_id' => $message_id,
		);
		if($channel)
			$body['channel'] = $channel;
			
		// post:
		$request = new WP_Http;
		$result = $request->request($url , array( 'method' => 'POST', 'body' => $body, 'headers' => $headers));
	}
}




function redmatrix_wp_displayAdminContent() {
	
	$seed_url = redmatrix_wp_get_seed_location();
	$password = redmatrix_wp_get_password();
	$user_acct = redmatrix_wp_get_acct_name();
	$channel = redmatrix_wp_get_channel_name();
	$backlink = get_option('redmatrix_wp_backlink');
	$backlink_checked = ((intval($backlink)) ? ' checked="checked" ' : '');
	// debug...
	// echo "seed location: $seed_url</br>";
	// echo "password: $password</br>";
	// echo "user_acct: $user_acct</br>";
	
	echo <<<EOF
	<div class='wrap'>
		<h2>CrossPost to Red Matrix</h2>
		<p>This plugin allows you to cross post to your Red Matrix channel.</p>
	</div>
	
	<div class="wrap">
		<h2>Configuration</h2>
		<form method="post" action="{$_SERVER["REQUEST_URI"]}">
			Enter the login details of your Red Matrix account<br /><br />
			Login (email): <input type="text" name="redmatrix_wp_acct_name" value="{$user_acct}"/><br />
			Password: <input type="password" name="redmatrix_wp_password" value="{$password}"/><br />
			Red Matrix URL: <input type="text" name="redmatrix_wp_url" value="{$seed_url}"/><br />
			Optional channel nickname: <input type="text" name="redmatrix_wp_channel" value="{$channel}"/><br />
			Add permalink to posts? <input type="checkbox" name="redmatrix_wp_backlink" value="1" {$backlink_checked} /><br />
			<input type="submit" value="Save" name="submit" />
		</form>
		<p></p>
	</div>
EOF;

	if(isset($_POST['submit']))	{
		echo "<div style='text-align:center;padding:4px;width:200px;background-color:#FFFF99;border:1xp solid #CCCCCC;color:#000000;'>Settings Saved!</div>";
	}
}

function redmatrix_wp_post_checkbox() {

    add_meta_box(
        'redmatrix_wp_meta_box_id', 
        'Cross Post to Red Matrix',
        'redmatrix_wp_post_meta_content',
        'post',
        'normal',
        'default'
    );
}

function redmatrix_wp_post_meta_content($post_id) {
    wp_nonce_field(plugin_basename( __FILE__ ), 'redmatrix_wp_nonce');
    echo '<input type="checkbox" name="redmatrix" value="1" /> Cross post?';
}

function redmatrix_wp_post_field_data($post_id) {

    // check if this isn't an auto save
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;

    // security check
	if((! array_key_exists('redmatrix_wp_nonce', $_POST))
    || (!wp_verify_nonce( $_POST['redmatrix_wp_nonce'], plugin_basename( __FILE__ ))))
        return;

    // now store data in custom fields based on checkboxes selected
    if (isset($_POST['redmatrix'])) {
		update_post_meta($post_id, 'redmatrix', '1');
	} 
}

function redmatrix_wp_display_admin_page() {
	
	if ((isset($_REQUEST["redmatrix_wp_acct_name"])) && (isset($_REQUEST["redmatrix_wp_password"]))) {
		
		$password = $_REQUEST["redmatrix_wp_password"];
		$red_url = $_REQUEST["redmatrix_wp_url"];
		$channelname = $_REQUEST['redmatrix_wp_channel'];

		
		update_option('redmatrix_wp_acct_name', $_REQUEST["redmatrix_wp_acct_name"]);
		update_option('redmatrix_wp_channel_name', $channelname);
		update_option('redmatrix_wp_seed_location', $red_url);
		update_option('redmatrix_wp_password', $password);
		update_option('redmatrix_wp_backlink', $_REQUEST['redmatrix_wp_backlink']);		
	}
	
	redmatrix_wp_displayAdminContent();
}

function redmatrix_wp_settings_link($links) { 
	$settings_link = '<a href="options-general.php?page=xpost-to-redmatrix">Settings</a>'; 
  	array_unshift($links, $settings_link); 
  	return $links; 
}

function redmatrix_wp_admin() {
	add_options_page("Crosspost to redmatrix", "Crosspost to redmatrix", "manage_options", "xpost-to-redmatrix", "redmatrix_wp_display_admin_page");
}

register_deactivation_hook( __FILE__, 'redmatrix_wp_deactivate' );

add_filter("plugin_action_links_$plugin", "redmatrix_wp_settings_link");

add_action("admin_menu", "redmatrix_wp_admin");
add_action('publish_post', 'redmatrix_wp_post');
add_action('add_meta_boxes', 'redmatrix_wp_post_checkbox');
add_action('save_post', 'redmatrix_wp_post_field_data');
add_action('before_delete_post', 'redmatrix_wp_delete_post');

add_action('delete_comment', 'redmatrix_wp_delete_comment');

add_filter('xmlrpc_methods', 'red_xmlrpc_methods');

add_filter('get_avatar', 'redmatrix_wp_get_avatar',10,5);

add_action('comment_post', 'redmatrix_wp_wp_comment',10,2);
add_action('wp_set_comment_status', 'redmatrix_wp_wp_comment',10,2);


function red_xmlrpc_methods($methods) {
	$methods['red.Comment'] = 'red_comment';
	return $methods;
}

function red_comment($args) {
	global $wp_xmlrpc_server;
	$wp_xmlrpc_server->escape( $args );

	$blog_id  = $args[0];
	$username = $args[1];
	$password = $args[2];
	$post       = $args[3];
	$content_struct = $args[4];

	if ( ! $user = $wp_xmlrpc_server->login( $username, $password ) )
		return $wp_xmlrpc_server->error;

	if ( is_numeric($post) )
		$post_id = absint($post);
	else
		$post_id = url_to_postid($post);

	if ( ! $post_id )
		return new IXR_Error( 404, __( 'Invalid post ID.' ) );
	if ( ! get_post($post_id) )
		return new IXR_Error( 404, __( 'Invalid post ID.' ) );

	$comment['comment_post_ID'] = $post_id;

	$comment['comment_author'] = '';
	if ( isset($content_struct['author']) )
		$comment['comment_author'] = $content_struct['author'];

	$comment['comment_author_email'] = '';
	if ( isset($content_struct['author_email']) )
		$comment['comment_author_email'] = $content_struct['author_email'];

	$comment['comment_author_url'] = '';
	if ( isset($content_struct['author_url']) )
		$comment['comment_author_url'] = $content_struct['author_url'];

	$comment['user_ID'] = 0;

	if ( get_option('require_name_email') ) {
		if ( 6 > strlen($comment['comment_author_email']) || '' == $comment['comment_author'] )
			return new IXR_Error( 403, __( 'Comment author name and email are required' ) );
		elseif ( !is_email($comment['comment_author_email']) )
			return new IXR_Error( 403, __( 'A valid email address is required' ) );
	}

	if(isset($content_struct['comment_id'])) {
		$comment['comment_ID'] = intval($content_struct['comment_id']);
		$edit = true;
	}
	$comment['comment_post_ID']  = $post_id;
	$comment['comment_parent']   = isset($content_struct['comment_parent']) ? absint($content_struct['comment_parent']) : 0;
	$comment['comment_content']  = isset($content_struct['content'])        ? $content_struct['content'] : null;

	do_action('xmlrpc_call', 'red.Comment');

	$comment['comment_approved'] = 0;

	if($edit) {
		$result = wp_update_comment($comment);
		$comment_ID = $comment['comment_ID'];
	}
	else {
       	$comment_ID = red_wp_new_comment( $comment );
		if($comment_ID) {
			add_comment_meta($comment_ID,'red_published',true,true);
			wp_set_comment_status($comment_ID,'approve');
		}
	}

	if(isset($content_struct['red_avatar'])) {
		add_comment_meta($comment_ID,'red_avatar',$content_struct['red_avatar'],true);
	}


	do_action( 'xmlrpc_call_success_red_Comment', $comment_ID, $args );

	return $comment_ID;
}


function redmatrix_wp_wp_comment($comment_ID,$comment_status) {


	$c = get_comment($comment_ID, ARRAY_A);

	if((! $c) || ($comment_status !== 'approve' && $comment_status != 1)) {
		return;
	}


	$p = get_post($c['comment_post_ID'], ARRAY_A);

	$m = get_comment_meta($c['comment_ID']);


	if ((get_post_meta($c['comment_post_ID'], "redmatrix", true) == '1') 
		|| (get_post_meta($c['comment_post_ID'], "post_from_red", true) == '1')) {

		if(get_comment_meta($c['comment_ID'],'red_published',true)) {
			return;
		}


		$user_name = redmatrix_wp_get_acct_name();
		$password = redmatrix_wp_get_password();
		$seed_location = redmatrix_wp_get_seed_location();
		$channel = redmatrix_wp_get_channel_name();
		$backlink = get_option('redmatrix_wp_backlink');



		// check for red owner
		if(intval($c['user_id']) && $c['user_id'] == $p['post_author']) {
			// by default we'll post as the channel that authenticated via the api.
			// so this is a no-op

		}
		else {
			// somebody else
			// register an xchan

			$arr = array();
			$arr['guid'] = $c['comment_author_email'];
			$arr['url'] = $c['comment_author_url'];
			$arr['name'] = $c['comment_author'];
			$arr['photo'] = get_avatar($c['comment_author_email']);

			if ((isset($user_name)) && (isset($password)) && (isset($seed_location))) {
				$headers = array('Authorization' => 'Basic ' . base64_encode("$user_name:$password"));
				$body = $arr;
				$url = $seed_location . '/api/red/xchan';
				$request = new WP_Http;
				$result = $request->request($url , array( 'method' => 'POST', 'body' => $body, 'headers' => $headers));
				if($result['body']) {
					$j = json_decode($result['body'],true);
					if($j)
						$xchan_hash = $j['hash'];
				}
			}
			if(! $xchan_hash) {
				return;
			}
		}


		$parent = site_url() . '/' . $c['comment_post_ID'];
		
		if ((isset($user_name)) && (isset($password)) && (isset($seed_location))) {
			// remove potential comments
			$message = preg_replace('/<!--(.*)-->/Uis', '', $c['comment_content']);

			$message_id = $parent . '.' . $c['comment_ID'];

			$bbcode = xpost_to_html2bbcode($message);
			
			$url = $seed_location . '/api/statuses/update';
			
			$headers = array('Authorization' => 'Basic '.base64_encode("$user_name:$password"));
			$body = array(
				'title'     => xpost_to_html2bbcode($post->post_title),
				'status'    => $bbcode,
				'source'    => 'WordPress', 
				'namespace' => 'wordpress',
				'remote_id' => $message_id,
				'permalink' => $post->guid . '#comments',
				'in_reply_to_status_id' => $parent,				
			);
			if($channel)
				$body['channel'] = $channel;
			if($xchan_hash)
				$body['remote_xchan'] = $xchan_hash;

			add_comment_meta($c['comment_ID'],'red_published',true,true);

			// post:
			$request = new WP_Http;
			$result = $request->request($url , array( 'method' => 'POST', 'body' => $body, 'headers' => $headers));

		}
	}
}




function redmatrix_wp_get_avatar($avatar,$id_or_email,$size,$default,$alt) {

	if(! is_object($id_or_email))
		return $avatar;
	if((! array_key_exists('comment_author_email',$id_or_email)) || (empty($id_or_email->comment_author_email)))
		return $avatar;
	if((! array_key_exists('comment_ID', $id_or_email)) || (! intval($id_or_email->comment_ID)))
		return $avatar;
	$l = get_comment_meta($id_or_email->comment_ID,'red_avatar',true);
	if($l) {
		$safe_alt = esc_attr($alt);
		$avatar = "<img alt='{$safe_alt}' src='{$l}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
	}
	return $avatar;
}


// from:
// http://www.docgate.com/tutorial/php/how-to-convert-html-to-bbcode-with-php-script.html
function xpost_to_html2bbcode($text) {
	$htmltags = array(
		'/\<b\>(.*?)\<\/b\>/is',
		'/\<i\>(.*?)\<\/i\>/is',
		'/\<u\>(.*?)\<\/u\>/is',
		'/\<ul.*?\>(.*?)\<\/ul\>/is',
		'/\<li\>(.*?)\<\/li\>/is',
		'/\<img(.*?) src=\"(.*?)\" alt=\"(.*?)\" title=\"Smile(y?)\" \/\>/is',		// some smiley
		'/\<img(.*?) src=\"http:\/\/(.*?)\" (.*?)\>/is',
		'/\<img(.*?) src=\"(.*?)\" alt=\":(.*?)\" .*? \/\>/is',						// some smiley
		'/\<div class=\"quotecontent\"\>(.*?)\<\/div\>/is',	
		'/\<div class=\"codecontent\"\>(.*?)\<\/div\>/is',	
		'/\<div class=\"quotetitle\"\>(.*?)\<\/div\>/is',	
		'/\<div class=\"codetitle\"\>(.*?)\<\/div\>/is',
		'/\<cite.*?\>(.*?)\<\/cite\>/is',
		'/\<blockquote.*?\>(.*?)\<\/blockquote\>/is',
		'/\<div\>(.*?)\<\/div\>/is',
		'/\<code\>(.*?)\<\/code\>/is',
		'/\<br(.*?)\>/is',
		'/\<strong\>(.*?)\<\/strong\>/is',
		'/\<em\>(.*?)\<\/em\>/is',
		'/\<a href=\"mailto:(.*?)\"(.*?)\>(.*?)\<\/a\>/is',
		'/\<a .*?href=\"(.*?)\"(.*?)\>http:\/\/(.*?)\<\/a\>/is',
		'/\<a .*?href=\"(.*?)\"(.*?)\>(.*?)\<\/a\>/is'
	);

	$bbtags = array(
		'[b]$1[/b]',
		'[i]$1[/i]',
		'[u]$1[/u]',
		'[list]$1[/list]',
		'[*]$1',
		'$3',
		'[img]http://$2[/img]' . "\n",
		':$3',
		'\[quote\]$1\[/quote\]',
		'\[code\]$1\[/code\]',
		'',
		'',
		'',
		'\[quote\]$1\[/quote\]',
		'$1',
		'\[code\]$1\[/code\]',
		"\n",
		'[b]$1[/b]',
		'[i]$1[/i]',
		'[email=$1]$3[/email]',
		'[url]$1[/url]',
		'[url=$1]$3[/url]'
	);

	$text = str_replace ("\n", ' ', $text);
	$ntext = preg_replace ($htmltags, $bbtags, $text);
	$ntext = preg_replace ($htmltags, $bbtags, $ntext);

	// for too large text and cannot handle by str_replace
	if (!$ntext) {
		$ntext = str_replace(array('<br>', '<br />'), "\n", $text);
		$ntext = str_replace(array('<strong>', '</strong>'), array('[b]', '[/b]'), $ntext);
		$ntext = str_replace(array('<em>', '</em>'), array('[i]', '[/i]'), $ntext);
	}

	$ntext = strip_tags($ntext);
	
	$ntext = trim(html_entity_decode($ntext,ENT_QUOTES,'UTF-8'));
	return $ntext;
}

// Just like wp_new_comment except we do not allow it to be pre-approved. This would cause a loop in our syncronisation.

function red_wp_new_comment( $commentdata ) {
	if ( isset( $commentdata['user_ID'] ) ) {
		$commentdata['user_id'] = $commentdata['user_ID'] = (int) $commentdata['user_ID'];
	}

	$prefiltered_user_id = ( isset( $commentdata['user_id'] ) ) ? (int) $commentdata['user_id'] : 0;

	/**
	 * Filter a comment's data before it is sanitized and inserted into the database.
	 *
	 * @since 1.5.0
	 *
	 * @param array $commentdata Comment data.
	 */
	$commentdata = apply_filters( 'preprocess_comment', $commentdata );

	$commentdata['comment_post_ID'] = (int) $commentdata['comment_post_ID'];
	if ( isset( $commentdata['user_ID'] ) && $prefiltered_user_id !== (int) $commentdata['user_ID'] ) {
		$commentdata['user_id'] = $commentdata['user_ID'] = (int) $commentdata['user_ID'];
	} elseif ( isset( $commentdata['user_id'] ) ) {
		$commentdata['user_id'] = (int) $commentdata['user_id'];
	}

	$commentdata['comment_parent'] = isset($commentdata['comment_parent']) ? absint($commentdata['comment_parent']) : 0;
	$parent_status = ( 0 < $commentdata['comment_parent'] ) ? wp_get_comment_status($commentdata['comment_parent']) : '';
	$commentdata['comment_parent'] = ( 'approved' == $parent_status || 'unapproved' == $parent_status ) ? $commentdata['comment_parent'] : 0;

	$commentdata['comment_author_IP'] = preg_replace( '/[^0-9a-fA-F:., ]/', '',$_SERVER['REMOTE_ADDR'] );
	$commentdata['comment_agent']     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 254 ) : '';

	$commentdata['comment_date']     = current_time('mysql');
	$commentdata['comment_date_gmt'] = current_time('mysql', 1);

	$commentdata = wp_filter_comment($commentdata);

	$commentdata['comment_approved'] = 0;

	$comment_ID = wp_insert_comment($commentdata);
	if ( ! $comment_ID ) {
		return false;
	}

	/**
	 * Fires immediately after a comment is inserted into the database.
	 *
	 * @since 1.2.0
	 *
	 * @param int $comment_ID       The comment ID.
	 * @param int $comment_approved 1 (true) if the comment is approved, 0 (false) if not.
	 */
	do_action( 'comment_post', $comment_ID, $commentdata['comment_approved'] );

	return $comment_ID;
}
