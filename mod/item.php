<?php

/**
 *
 * This is the POST destination for most all locally posted
 * text stuff. This function handles status, wall-to-wall status, 
 * local comments, and remote coments that are posted on this site 
 * (as opposed to being delivered in a feed).
 * Also processed here are posts and comments coming through the 
 * statusnet/twitter API. 
 * All of these become an "item" which is our basic unit of 
 * information.
 * Posts that originate externally or do not fall into the above 
 * posting categories go through item_store() instead of this function. 
 *
 */  

require_once('include/crypto.php');
require_once('include/enotify.php');
require_once('include/email.php');

function item_post(&$a) {

	if((! local_user()) && (! remote_user()) && (! x($_REQUEST,'commenter')))
		return;

	require_once('include/security.php');

	$uid = local_user();

	if(x($_REQUEST,'dropitems')) {
		require_once('include/items.php');
		$arr_drop = explode(',',$_REQUEST['dropitems']);
		drop_items($arr_drop);
		$json = array('success' => 1);
		echo json_encode($json);
		killme();
	}

	call_hooks('post_local_start', $_REQUEST);
//	logger('postinput ' . file_get_contents('php://input'));
	logger('postvars ' . print_r($_REQUEST,true), LOGGER_DATA);

	$api_source = ((x($_REQUEST,'api_source') && $_REQUEST['api_source']) ? true : false);

	// 'origin' (if non-zero) indicates that this network is where the message originated,
	// for the purpose of relaying comments to other conversation members. 
	// If using the API from a device (leaf node) you must set origin to 1 (default) or leave unset.
	// If the API is used from another network with its own distribution
	// and deliveries, you may wish to set origin to 0 or false and allow the other 
	// network to relay comments.

	// If you are unsure, it is prudent (and important) to leave it unset.   

	$origin = (($api_source && array_key_exists('origin',$_REQUEST)) ? intval($_REQUEST['origin']) : 1);


	$return_path = ((x($_REQUEST,'return')) ? $_REQUEST['return'] : '');
	$preview = ((x($_REQUEST,'preview')) ? intval($_REQUEST['preview']) : 0);
	$categories = ((x($_REQUEST['category'])) ? escape_tags($_REQUEST['category']) : '');

	/**
	 * Is this a reply to something?
	 */

	$parent = ((x($_REQUEST,'parent')) ? intval($_REQUEST['parent']) : 0);
	$parent_uri = ((x($_REQUEST,'parent_uri')) ? trim($_REQUEST['parent_uri']) : '');

	$parent_item = null;
	$parent_contact = null;
	$thr_parent = '';
	$parid = 0;
	$r = false;

	if($parent || $parent_uri) {

		if(! x($_REQUEST,'type'))
			$_REQUEST['type'] = 'net-comment';

		if($parent) {
			$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
				intval($parent)
			);
		}
		elseif($parent_uri && local_user()) {
			// This is coming from an API source, and we are logged in
			$r = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($parent_uri),
				intval(local_user())
			);
		}
		// if this isn't the real parent of the conversation, find it
		if($r !== false && count($r)) {
			$parid = $r[0]['parent'];
			$parent_uri = $r[0]['uri'];
			if($r[0]['id'] != $r[0]['parent']) {
				$r = q("SELECT * FROM `item` WHERE `id` = `parent` AND `parent` = %d LIMIT 1",
					intval($parid)
				);
			}
		}

		if(($r === false) || (! count($r))) {
			notice( t('Unable to locate original post.') . EOL);
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
		$parent_item = $r[0];
		$parent = $r[0]['id'];

		// multi-level threading - preserve the info but re-parent to our single level threading
		//if(($parid) && ($parid != $parent))
			$thr_parent = $parent_uri;

		if($parent_item['contact-id'] && $uid) {
			$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($parent_item['contact-id']),
				intval($uid)
			);
			if(count($r))
				$parent_contact = $r[0];
		}
	}

	if($parent) logger('mod_item: item_post parent=' . $parent);

	$profile_uid = ((x($_REQUEST,'profile_uid')) ? intval($_REQUEST['profile_uid']) : 0);
	$post_id     = ((x($_REQUEST,'post_id'))     ? intval($_REQUEST['post_id'])     : 0);
	$app         = ((x($_REQUEST,'source'))      ? strip_tags($_REQUEST['source'])  : '');

	$allow_moderated = false;

	// here is where we are going to check for permission to post a moderated comment.

	// First check that the parent exists and it is a wall item.

	if((x($_REQUEST,'commenter')) && ((! $parent) || (! $parent_item['wall']))) {
		notice( t('Permission denied.') . EOL) ;
		if(x($_REQUEST,'return')) 
			goaway($a->get_baseurl() . "/" . $return_path );
		killme();
	}

	// Now check that it is a page_type of PAGE_BLOG, and that valid personal details
	// have been provided, and run any anti-spam plugins


	// TODO




	if((! can_write_wall($a,$profile_uid)) && (! $allow_moderated)) {
		notice( t('Permission denied.') . EOL) ;
		if(x($_REQUEST,'return')) 
			goaway($a->get_baseurl() . "/" . $return_path );
		killme();
	}


	// is this an edited post?

	$orig_post = null;

	if($post_id) {
		$i = q("SELECT * FROM `item` WHERE `uid` = %d AND `id` = %d LIMIT 1",
			intval($profile_uid),
			intval($post_id)
		);
		if(! count($i))
			killme();
		$orig_post = $i[0];
	}

	$user = null;

	$r = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
		intval($profile_uid)
	);
	if(count($r))
		$user = $r[0];

	if($orig_post) {
		$str_group_allow   = $orig_post['allow_gid'];
		$str_contact_allow = $orig_post['allow_cid'];
		$str_group_deny    = $orig_post['deny_gid'];
		$str_contact_deny  = $orig_post['deny_cid'];
		$location          = $orig_post['location'];
		$coord             = $orig_post['coord'];
		$verb              = $orig_post['verb'];
		$emailcc           = $orig_post['emailcc'];
		$app			   = $orig_post['app'];
//		$categories        = $orig_post['file'];
		$title             = escape_tags(trim($_REQUEST['title']));
		$body              = escape_tags(trim($_REQUEST['body']));
		$private           = $orig_post['private'];
		$pubmail_enable    = $orig_post['pubmail'];

	}
	else {

		// if coming from the API and no privacy settings are set, 
		// use the user default permissions - as they won't have
		// been supplied via a form.

		if(($api_source) 
			&& (! array_key_exists('contact_allow',$_REQUEST))
			&& (! array_key_exists('group_allow',$_REQUEST))
			&& (! array_key_exists('contact_deny',$_REQUEST))
			&& (! array_key_exists('group_deny',$_REQUEST))) {
			$str_group_allow   = $user['allow_gid'];
			$str_contact_allow = $user['allow_cid'];
			$str_group_deny    = $user['deny_gid'];
			$str_contact_deny  = $user['deny_cid'];
		}
		else {

			// use the posted permissions

			$str_group_allow   = perms2str($_REQUEST['group_allow']);
			$str_contact_allow = perms2str($_REQUEST['contact_allow']);
			$str_group_deny    = perms2str($_REQUEST['group_deny']);
			$str_contact_deny  = perms2str($_REQUEST['contact_deny']);
		}

		$title             = escape_tags(trim($_REQUEST['title']));
		$location          = notags(trim($_REQUEST['location']));
		$coord             = notags(trim($_REQUEST['coord']));
		$verb              = notags(trim($_REQUEST['verb']));
		$emailcc           = notags(trim($_REQUEST['emailcc']));
		$body              = escape_tags(trim($_REQUEST['body']));

		$language = detect_language($body);

		logger('detected language: ' . $language);






		$private = ((strlen($str_group_allow) || strlen($str_contact_allow) || strlen($str_group_deny) || strlen($str_contact_deny)) ? 1 : 0);

		// If this is a comment, set the permissions from the parent.

		if($parent_item) {
			$private = 0;

			if(($parent_item['private']) 
				|| strlen($parent_item['allow_cid']) 
				|| strlen($parent_item['allow_gid']) 
				|| strlen($parent_item['deny_cid']) 
				|| strlen($parent_item['deny_gid'])) {
				$private = (($parent_item['private']) ? $parent_item['private'] : 1);
			}

			$str_contact_allow = $parent_item['allow_cid'];
			$str_group_allow   = $parent_item['allow_gid'];
			$str_contact_deny  = $parent_item['deny_cid'];
			$str_group_deny    = $parent_item['deny_gid'];
		}
	
		$pubmail_enable    = ((x($_REQUEST,'pubmail_enable') && intval($_REQUEST['pubmail_enable']) && (! $private)) ? 1 : 0);

		// if using the API, we won't see pubmail_enable - figure out if it should be set

		if($api_source && $profile_uid && $profile_uid == local_user() && (! $private)) {
			$mail_disabled = ((function_exists('imap_open') && (! get_config('system','imap_disabled'))) ? 0 : 1);
			if(! $mail_disabled) {
				$r = q("SELECT * FROM `mailacct` WHERE `uid` = %d AND `server` != '' LIMIT 1",
					intval(local_user())
				);
				if(count($r) && intval($r[0]['pubmail']))
					$pubmail_enabled = true;
			}
		}

		if(! strlen($body)) {
			if($preview)
				killme();
			info( t('Empty post discarded.') . EOL );
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}


	// Work around doubled linefeeds in Tinymce 3.5b2
	// First figure out if it's a status post that would've been
	// created using tinymce. Otherwise leave it alone. 

	$plaintext = (local_user() ? intval(get_pconfig(local_user(),'system','plaintext')) : 0);
	if((! $parent) && (! $api_source) && (! $plaintext)) {
		$body = fix_mce_lf($body);
	}


	// get contact info for poster

	$author = null;
	$self   = false;

	if((local_user()) && (local_user() == $profile_uid)) {
		$self = true;
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($_SESSION['uid'])
		);
	}
	elseif(remote_user()) {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d LIMIT 1",
			intval(remote_user())
		);
	}

	if(count($r)) {
		$author = $r[0];
		$contact_id = $author['id'];
	}

	// get contact info for owner
	
	if($profile_uid == local_user()) {
		$contact_record = $author;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($profile_uid)
		);
		if(count($r))
			$contact_record = $r[0];
	}

	$post_type = notags(trim($_REQUEST['type']));

	if($post_type === 'net-comment') {
		if($parent_item !== null) {
			if($parent_item['wall'] == 1)
				$post_type = 'wall-comment';
			else
				$post_type = 'remote-comment';
		}
	}

	/**
	 *
	 * When a photo was uploaded into the message using the (profile wall) ajax 
	 * uploader, The permissions are initially set to disallow anybody but the
	 * owner from seeing it. This is because the permissions may not yet have been
	 * set for the post. If it's private, the photo permissions should be set
	 * appropriately. But we didn't know the final permissions on the post until
	 * now. So now we'll look for links of uploaded messages that are in the
	 * post and set them to the same permissions as the post itself.
	 *
	 */

	$match = null;

	if((! $preview) && preg_match_all("/\[img\](.*?)\[\/img\]/",$body,$match)) {
		$images = $match[1];
		if(count($images)) {
			foreach($images as $image) {
				if(! stristr($image,$a->get_baseurl() . '/photo/'))
					continue;
				$image_uri = substr($image,strrpos($image,'/') + 1);
				$image_uri = substr($image_uri,0, strpos($image_uri,'-'));
				if(! strlen($image_uri))
					continue;
				$srch = '<' . intval($contact_id) . '>';

				$r = q("SELECT `id` FROM `photo` WHERE `allow_cid` = '%s' AND `allow_gid` = '' AND `deny_cid` = '' AND `deny_gid` = ''
					AND `resource-id` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($srch),
					dbesc($image_uri),
					intval($profile_uid)
				);

				if(! count($r))
					continue;
 

				$r = q("UPDATE `photo` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'
					WHERE `resource-id` = '%s' AND `uid` = %d AND `album` = '%s' ",
					dbesc($str_contact_allow),
					dbesc($str_group_allow),
					dbesc($str_contact_deny),
					dbesc($str_group_deny),
					dbesc($image_uri),
					intval($profile_uid),
					dbesc( t('Wall Photos'))
				);
 
			}
		}
	}


	/**
	 * Next link in any attachment references we find in the post.
	 */

	$match = false;

	if((! $preview) && preg_match_all("/\[attachment\](.*?)\[\/attachment\]/",$body,$match)) {
		$attaches = $match[1];
		if(count($attaches)) {
			foreach($attaches as $attach) {
				$r = q("SELECT * FROM `attach` WHERE `uid` = %d AND `id` = %d LIMIT 1",
					intval($profile_uid),
					intval($attach)
				);				
				if(count($r)) {
					$r = q("UPDATE `attach` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'
						WHERE `uid` = %d AND `id` = %d LIMIT 1",
						dbesc($str_contact_allow),
						dbesc($str_group_allow),
						dbesc($str_contact_deny),
						dbesc($str_group_deny),
						intval($profile_uid),
						intval($attach)
					);
				}
			}
		}
	}

	$body = bb_translate_video($body);

	/**
	 * Fold multi-line [code] sequences
	 */

	$body = preg_replace('/\[\/code\]\s*\[code\]/ism',"\n",$body); 

	$body = scale_external_images($body,false);

	/**
	 * Look for any tags and linkify them
	 */

	$str_tags = '';
	$inform   = '';
	$post_tags = array();

	$tags = get_tags($body);

	/**
	 * add a statusnet style reply tag if the original post was from there
	 * and we are replying, and there isn't one already
	 */

	if(($parent_contact) && ($parent_contact['network'] === NETWORK_OSTATUS) 
		&& ($parent_contact['nick']) && (! in_array('@' . $parent_contact['nick'],$tags))) {
		$body = '@' . $parent_contact['nick'] . ' ' . $body;
		$tags[] = '@' . $parent_contact['nick'];
	}		

	$tagged = array();

	$private_forum = false;

	if(count($tags)) {
		foreach($tags as $tag) {

			// If we already tagged 'Robert Johnson', don't try and tag 'Robert'.
			// Robert Johnson should be first in the $tags array

			$fullnametagged = false;
			for($x = 0; $x < count($tagged); $x ++) {
				if(stristr($tagged[$x],$tag . ' ')) {
					$fullnametagged = true;
					break;
				}
			}
			if($fullnametagged)
				continue;

			$success = handle_tag($a, $body, $inform, $str_tags, (local_user()) ? local_user() : $profile_uid , $tag); 
			logger('handle_tag: ' . print_r($success,tue));

			if($success['replaced']) {
				$tagged[] = $tag;
				$post_tags[] = array(
					'uid' => $profile_uid, 
					'type' => $success['termtype'],
					'otype' => TERM_OBJ_POST,
					'term' => substr($tag,1),
					'url' => $success['url']
				); 				
			}
			if(is_array($success['contact']) && intval($success['contact']['prv'])) {
				$private_forum = true;
				$private_id = $success['contact']['id'];
			}
		}
	}

	if(strlen($categories)) {
		$cats = explode(',',$categories);
		foreach($cats as $cat) {
			$post_tags[] = array(
				'uid' => $profile_uid, 
				'type' => TERM_CATEGORY,
				'otype' => TERM_OBJ_POST,
				'term' => trim($cat),
				'url' => ''
			); 				
		}
	}

	logger('post_tags: ' . print_r($post_tags,true));

	if(($private_forum) && (! $parent) && (! $private)) {
		// we tagged a private forum in a top level post and the message was public.
		// Restrict it.
		$private = 1;
		$str_contact_allow = '<' . $private_id . '>'; 
	}

	$attachments = '';
	$match = false;

	if(preg_match_all('/(\[attachment\]([0-9]+)\[\/attachment\])/',$body,$match)) {
		foreach($match[2] as $mtch) {
			$r = q("SELECT `id`,`filename`,`filesize`,`filetype` FROM `attach` WHERE `uid` = %d AND `id` = %d LIMIT 1",
				intval($profile_uid),
				intval($mtch)
			);
			if(count($r)) {
				if(strlen($attachments))
					$attachments .= ',';
				$attachments .= '[attach]href="' . $a->get_baseurl() . '/attach/' . $r[0]['id'] . '" length="' . $r[0]['filesize'] . '" type="' . $r[0]['filetype'] . '" title="' . (($r[0]['filename']) ? $r[0]['filename'] : '') . '"[/attach]'; 
			}
			$body = str_replace($match[1],'',$body);
		}
	}

	$wall = 0;

	if($post_type === 'wall' || $post_type === 'wall-comment')
		$wall = 1;

	if(! strlen($verb))
		$verb = ACTIVITY_POST ;

	$gravity = (($parent) ? 6 : 0 );

	$notify_type = (($parent) ? 'comment-new' : 'wall-new' );

	$uri = item_new_uri($a->get_hostname(),$profile_uid);

	// Fallback so that we alway have a thr-parent
	if(!$thr_parent)
		$thr_parent = $uri;

	$datarray = array();
	$datarray['uid']           = $profile_uid;
	$datarray['type']          = $post_type;
	$datarray['wall']          = $wall;
	$datarray['gravity']       = $gravity;
	$datarray['contact-id']    = $contact_id;
	$datarray['owner-name']    = $contact_record['name'];
	$datarray['owner-link']    = $contact_record['url'];
	$datarray['owner-avatar']  = $contact_record['thumb'];
	$datarray['author-name']   = $author['name'];
	$datarray['author-link']   = $author['url'];
	$datarray['author-avatar'] = $author['thumb'];
	$datarray['created']       = datetime_convert();
	$datarray['edited']        = datetime_convert();
	$datarray['commented']     = datetime_convert();
	$datarray['received']      = datetime_convert();
	$datarray['changed']       = datetime_convert();
	$datarray['uri']           = $uri;
	$datarray['title']         = $title;
	$datarray['body']          = $body;
	$datarray['app']           = $app;
	$datarray['lang']          = $language;
	$datarray['location']      = $location;
	$datarray['coord']         = $coord;
	$datarray['inform']        = $inform;
	$datarray['verb']          = $verb;
	$datarray['allow_cid']     = $str_contact_allow;
	$datarray['allow_gid']     = $str_group_allow;
	$datarray['deny_cid']      = $str_contact_deny;
	$datarray['deny_gid']      = $str_group_deny;
	$datarray['private']       = $private;
	$datarray['pubmail']       = $pubmail_enable;
	$datarray['attach']        = $attachments;
	$datarray['thr-parent']    = $thr_parent;
	$datarray['postopts']      = '';
	$datarray['origin']        = $origin;
	$datarray['moderated']     = $allow_moderated;

	/**
	 * These fields are for the convenience of plugins...
	 * 'self' if true indicates the owner is posting on their own wall
	 * If parent is 0 it is a top-level post.
	 */

	$datarray['parent']        = $parent;
	$datarray['self']          = $self;
//	$datarray['prvnets']       = $user['prvnets'];

	if($orig_post)
		$datarray['edit']      = true;

	// preview mode - prepare the body for display and send it via json

	if($preview) {
		require_once('include/conversation.php');
		$o = conversation($a,array(array_merge($contact_record,$datarray)),'search',false,'preview');
		logger('preview: ' . $o, LOGGER_DEBUG);
		echo json_encode(array('preview' => $o));
		killme();
	}

	// post owner can post in any language they wish; others however are subject to the owner's whims about language acceptance

	if($profile_uid != local_user()) {
		$allowed_languages = get_pconfig($profile_uid,'system','allowed_languages');
	
		if((is_array($allowed_languages)) && ($datarray['lang']) && (! array_key_exists($datarray['lang'],$allowed_languages))) {
			$translate = array('item' => $datarray, 'from' => $datarray['lang'], 'to' => $allowed_languages, 'translated' => false);
			call_hooks('item_translate', $translate);
			if((! $translate['translated']) && (intval(get_pconfig($profile_uid,'system','reject_disallowed_languages')))) {
				logger('item_store: language ' . $datarray['lang'] . ' not accepted for uid ' . $datarray['uid']);
				return;
			}
			$datarray = $translate['item'];
		}
	}

	call_hooks('post_local',$datarray);

	if(x($datarray,'cancel')) {
		logger('mod_item: post cancelled by plugin.');
		if($return_path) {
			goaway($a->get_baseurl() . "/" . $return_path);
		}

		$json = array('cancel' => 1);
		if(x($_REQUEST,'jsreload') && strlen($_REQUEST['jsreload']))
			$json['reload'] = $a->get_baseurl() . '/' . $_REQUEST['jsreload'];

		echo json_encode($json);
		killme();
	}


	if($orig_post) {
		$r = q("UPDATE `item` SET `title` = '%s', `body` = '%s', `attach` = '%s', `edited` = '%s' WHERE `id` = %d AND `uid` = %d LIMIT 1",
			dbesc($datarray['title']),
			dbesc($datarray['body']),
			dbesc($datarray['attach']),
			dbesc(datetime_convert()),
			intval($post_id),
			intval($profile_uid)
		);

		// remove taxonomy items for this post - we'll recreate them

		q("delete from term where otype = %d and oid = %d and type in (%d, %d, %d, %d) ",
			intval(TERM_OBJ_POST),
			intval($post_id),
			intval(TERM_UNKNOWN),
			intval(TERM_HASHTAG),
			intval(TERM_MENTION),
			intval(TERM_CATEGORY)
		);


		proc_run('php', "include/notifier.php", 'edit_post', "$post_id");
		if((x($_REQUEST,'return')) && strlen($return_path)) {
			logger('return: ' . $return_path);
			goaway($a->get_baseurl() . "/" . $return_path );
		}
		killme();
	}
	else
		$post_id = 0;


	$r = q("INSERT INTO `item` (`uid`,`type`,`wall`,`gravity`,`contact-id`,`owner-name`,`owner-link`,`owner-avatar`, 
		`author-name`, `author-link`, `author-avatar`, `created`, `edited`, `commented`, `received`, `changed`, `uri`, `thr-parent`, `title`, `body`, `app`, `lang`, `location`, `coord`, 
		`inform`, `verb`, `postopts`, `allow_cid`, `allow_gid`, `deny_cid`, `deny_gid`, `private`, `pubmail`, `attach`,`origin`, `moderated`)
		VALUES( %d, '%s', %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', %d, %d )",
		intval($datarray['uid']),
		dbesc($datarray['type']),
		intval($datarray['wall']),
		intval($datarray['gravity']),
		intval($datarray['contact-id']),
		dbesc($datarray['owner-name']),
		dbesc($datarray['owner-link']),
		dbesc($datarray['owner-avatar']),
		dbesc($datarray['author-name']),
		dbesc($datarray['author-link']),
		dbesc($datarray['author-avatar']),
		dbesc($datarray['created']),
		dbesc($datarray['edited']),
		dbesc($datarray['commented']),
		dbesc($datarray['received']),
		dbesc($datarray['changed']),
		dbesc($datarray['uri']),
		dbesc($datarray['thr-parent']),
		dbesc($datarray['title']),
		dbesc($datarray['body']),
		dbesc($datarray['app']),
		dbesc($datarray['lang']),
		dbesc($datarray['location']),
		dbesc($datarray['coord']),
		dbesc($datarray['inform']),
		dbesc($datarray['verb']),
		dbesc($datarray['postopts']),
		dbesc($datarray['allow_cid']),
		dbesc($datarray['allow_gid']),
		dbesc($datarray['deny_cid']),
		dbesc($datarray['deny_gid']),
		intval($datarray['private']),
		intval($datarray['pubmail']),
		dbesc($datarray['attach']),
		intval($datarray['origin']),
		intval($datarray['moderated'])
	);

	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' LIMIT 1",
		dbesc($datarray['uri']));
	if(count($r)) {
		$post_id = $r[0]['id'];
		logger('mod_item: saved item ' . $post_id);


		if(count($post_tags)) {
			foreach($post_tags as $tag) {
				if(strlen(trim($tag['term']))) {
					q("insert into term (uid,oid,otype,type,term,url) values (%d,%d,%d,%d,'%s','%s')",
						intval($tag['uid']),
						intval($post_id),
						intval($tag['otype']),
						intval($tag['type']),
						dbesc(trim($tag['term'])),
						dbesc(trim($tag['url']))
					);
				}
			}
		}

		if($parent) {

			$r = q("UPDATE `item` SET `changed` = '%s' WHERE `parent` = %d ",
				dbesc(datetime_convert()),
				intval($parent)
			);

			// Inherit ACL's from the parent item.

			$r = q("UPDATE `item` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s', `private` = %d
				WHERE `id` = %d LIMIT 1",
				dbesc($parent_item['allow_cid']),
				dbesc($parent_item['allow_gid']),
				dbesc($parent_item['deny_cid']),
				dbesc($parent_item['deny_gid']),
				intval($parent_item['private']),
				intval($post_id)
			);

			if($contact_record != $author) {
				notification(array(
					'type'         => NOTIFY_COMMENT,
					'notify_flags' => $user['notify-flags'],
					'language'     => $user['language'],
					'to_name'      => $user['username'],
					'to_email'     => $user['email'],
					'uid'          => $user['uid'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'source_name'  => $datarray['author-name'],
					'source_link'  => $datarray['author-link'],
					'source_photo' => $datarray['author-avatar'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item',
					'parent'       => $parent,
					'parent_uri'   => $parent_item['uri']
				));
			
			}

		}
		else {
			$parent = $post_id;

			if($contact_record != $author) {
				notification(array(
					'type'         => NOTIFY_WALL,
					'notify_flags' => $user['notify-flags'],
					'language'     => $user['language'],
					'to_name'      => $user['username'],
					'to_email'     => $user['email'],
					'uid'          => $user['uid'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'source_name'  => $datarray['author-name'],
					'source_link'  => $datarray['author-link'],
					'source_photo' => $datarray['author-avatar'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item'
				));
			}
		}

		// fallback so that parent always gets set to non-zero.

		if(! $parent)
			$parent = $post_id;

		$r = q("UPDATE `item` SET `parent` = %d, `parent_uri` = '%s', `plink` = '%s', `changed` = '%s', `visible` = 1
			WHERE `id` = %d LIMIT 1",
			intval($parent),
			dbesc(($parent == $post_id) ? $uri : $parent_item['uri']),
			dbesc($a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id),
			dbesc(datetime_convert()),
			intval($post_id)
		);

		// photo comments turn the corresponding item visible to the profile wall
		// This way we don't see every picture in your new photo album posted to your wall at once.
		// They will show up as people comment on them.

		if(! $parent_item['visible']) {
			$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d LIMIT 1",
				intval($parent_item['id'])
			);
		}
	}
	else {
		logger('mod_item: unable to retrieve post that was just stored.');
		notice( t('System error. Post not saved.') . EOL);
		goaway($a->get_baseurl() . "/" . $return_path );
		// NOTREACHED
	}

	// update the commented timestamp on the parent

	q("UPDATE `item` set `commented` = '%s', `changed` = '%s' WHERE `id` = %d LIMIT 1",
		dbesc(datetime_convert()),
		dbesc(datetime_convert()),
		intval($parent)
	);

	$datarray['id']    = $post_id;
	$datarray['plink'] = $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id;

	call_hooks('post_local_end', $datarray);

	if(strlen($emailcc) && $profile_uid == local_user()) {
		$erecips = explode(',', $emailcc);
		if(count($erecips)) {
			foreach($erecips as $recip) {
				$addr = trim($recip);
				if(! strlen($addr))
					continue;
				$disclaimer = '<hr />' . sprintf( t('This message was sent to you by %s, a member of the Friendica social network.'),$a->user['username']) 
					. '<br />';
				$disclaimer .= sprintf( t('You may visit them online at %s'), $a->get_baseurl() . '/profile/' . $a->user['nickname']) . EOL;
				$disclaimer .= t('Please contact the sender by replying to this post if you do not wish to receive these messages.') . EOL; 

				$subject  = email_header_encode('[Friendica]' . ' ' . sprintf( t('%s posted an update.'),$a->user['username']),'UTF-8');
				$headers  = 'From: ' . email_header_encode($a->user['username'],'UTF-8') . ' <' . $a->user['email'] . '>' . "\n";
				$headers .= 'MIME-Version: 1.0' . "\n";
				$headers .= 'Content-Type: text/html; charset=UTF-8' . "\n";
				$headers .= 'Content-Transfer-Encoding: 8bit' . "\n\n";
				$link = '<a href="' . $a->get_baseurl() . '/profile/' . $a->user['nickname'] . '"><img src="' . $author['thumb'] . '" alt="' . $a->user['username'] . '" /></a><br /><br />';
				$html    = prepare_body($datarray);
				$message = '<html><body>' . $link . $html . $disclaimer . '</body></html>';
				@mail($addr, $subject, $message, $headers);
			}
		}
	}

	// This is a real juggling act on shared hosting services which kill your processes
	// e.g. dreamhost. We used to start delivery to our native delivery agents in the background
	// and then run our plugin delivery from the foreground. We're now doing plugin delivery first,
	// because as soon as you start loading up a bunch of remote delivey processes, *this* page is
	// likely to get killed off. If you end up looking at an /item URL and a blank page,
	// it's very likely the delivery got killed before all your friends could be notified.
	// Currently the only realistic fixes are to use a reliable server - which precludes shared hosting,
	// or cut back on plugins which do remote deliveries.  

	proc_run('php', "include/notifier.php", $notify_type, "$post_id");

	logger('post_complete');

	// figure out how to return, depending on from whence we came

	if($api_source)
		return;

	if($return_path) {
		goaway($a->get_baseurl() . "/" . $return_path);
	}

	$json = array('success' => 1);
	if(x($_REQUEST,'jsreload') && strlen($_REQUEST['jsreload']))
		$json['reload'] = $a->get_baseurl() . '/' . $_REQUEST['jsreload'];

	logger('post_json: ' . print_r($json,true), LOGGER_DEBUG);

	echo json_encode($json);
	killme();
	// NOTREACHED
}





function item_content(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	if(($a->argc == 3) && ($a->argv[1] === 'drop') && intval($a->argv[2])) {
		require_once('include/items.php');
		drop_item($a->argv[2]);
	}
}

/**
 * This function removes the tag $tag from the text $body and replaces it with 
 * the appropiate link. 
 * 
 * @param unknown_type $body the text to replace the tag in
 * @param unknown_type $inform a comma-seperated string containing everybody to inform
 * @param unknown_type $str_tags string to add the tag to
 * @param unknown_type $profile_uid
 * @param unknown_type $tag the tag to replace
 *
 * @return boolean true if replaced, false if not replaced
 */
function handle_tag($a, &$body, &$inform, &$str_tags, $profile_uid, $tag) {

	$replaced = false;
	$r = null;

	$termtype = ((strpos($tag,'#') === 0) ? TERM_HASHTAG : TERM_UNKNOWN);
	$termtype = ((strpos($tag,'@') === 0) ? TERM_MENTION : $termtype);

	//is it a hash tag? 
	if(strpos($tag,'#') === 0) {
		//if the tag is replaced...
		if(strpos($tag,'[url='))
			//...do nothing
			return $replaced;
		//base tag has the tags name only
		$basetag = str_replace('_',' ',substr($tag,1));
		//create text for link
		$url = $a->get_baseurl() . '/search?tag=' . rawurlencode($basetag);
		$newtag = '#[url=' . $a->get_baseurl() . '/search?tag=' . rawurlencode($basetag) . ']' . $basetag . '[/url]';
		//replace tag by the link
		$body = str_replace($tag, $newtag, $body);
		$replaced = true;

		//is the link already in str_tags?
		if(! stristr($str_tags,$newtag)) {
			//append or set str_tags
			if(strlen($str_tags))
				$str_tags .= ',';
			$str_tags .= $newtag;
		}
		return array('replaced' => $replaced, 'termtype' => $termtype, 'url' => $url, 'contact' => $r[0]);	
	}
	//is it a person tag? 
	if(strpos($tag,'@') === 0) {
		//is it already replaced? 
		if(strpos($tag,'[url='))
			return $replaced;
		$stat = false;
		//get the person's name
		$name = substr($tag,1);
		//is it a link or a full dfrn address? 
		if((strpos($name,'@')) || (strpos($name,'http://'))) {
			$newname = $name;
			//get the profile links
			$links = @lrdd($name);
			if(count($links)) {
				//for all links, collect how is to inform and how's profile is to link
				foreach($links as $link) {
					if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
						$profile = $link['@attributes']['href'];
					if($link['@attributes']['rel'] === 'salmon') {
						if(strlen($inform))
							$inform .= ',';
						$inform .= 'url:' . str_replace(',','%2c',$link['@attributes']['href']);
					}
				}
			}
		} else { //if it is a name rather than an address
			$newname = $name;
			$alias = '';
			$tagcid = 0;
			//is it some generated name?
			if(strrpos($newname,'+')) {
				//get the id
				$tagcid = intval(substr($newname,strrpos($newname,'+') + 1));
				//remove the next word from tag's name
				if(strpos($name,' ')) {
					$name = substr($name,0,strpos($name,' '));
				}
			}
			if($tagcid) { //if there was an id
				//select contact with that id from the logged in user's contact list
				$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
						intval($tagcid),
						intval($profile_uid)
				);
			} elseif(strstr($name,'_') || strstr($name,' ')) { //no id
				//get the real name
				$newname = str_replace('_',' ',$name);
				//select someone from this user's contacts by name
				$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
						dbesc($newname),
						intval($profile_uid)
				);
			} else {
				//select someone by attag or nick and the name passed in
				$r = q("SELECT * FROM `contact` WHERE `attag` = '%s' OR `nick` = '%s' AND `uid` = %d ORDER BY `attag` DESC LIMIT 1",
						dbesc($name),
						dbesc($name),
						intval($profile_uid)
				);
			}
			//$r is set, if someone could be selected
			if(count($r)) {
				$profile = $r[0]['url'];
				//set newname to nick, find alias
				if($r[0]['network'] === 'stat') {
					$newname = $r[0]['nick'];
					$stat = true;
					if($r[0]['alias'])
						$alias = $r[0]['alias'];
				}
				else
					$newname = $r[0]['name'];
				//add person's id to $inform
				if(strlen($inform))
					$inform .= ',';
				$inform .= 'cid:' . $r[0]['id'];
			}
		}
		//if there is an url for this persons profile
		if(isset($profile)) {
			$replaced = true;
			//create profile link
			$profile = str_replace(',','%2c',$profile);
			$url = $profile;
			$newtag = '@[url=' . $profile . ']' . $newname	. '[/url]';
			$body = str_replace('@' . $name, $newtag, $body);
			//append tag to str_tags
			if(! stristr($str_tags,$newtag)) {
				if(strlen($str_tags))
					$str_tags .= ',';
				$str_tags .= $newtag;
			}
		}
	}

	return array('replaced' => $replaced, 'termtype' => $termtype, 'url' => $url, 'contact' => $r[0]);	
}


