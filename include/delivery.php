<?php
require_once("boot.php");
require_once('include/queue_fn.php');
require_once('include/html2plain.php');

function delivery_run($argv, $argc){
	global $a, $db;

	if(is_null($a)){
		$a = new App;
	}

	if(is_null($db)) {
		@include(".htconfig.php");
		require_once("dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
		        unset($db_host, $db_user, $db_pass, $db_data);
	}

	require_once("session.php");
	require_once("datetime.php");
	require_once('include/items.php');
	require_once('include/bbcode.php');
	require_once('include/email.php');

	load_config('config');
	load_config('system');

	load_hooks();

	if($argc < 3)
		return;

	$a->set_baseurl(get_config('system','url'));

	logger('delivery: invoked: ' . print_r($argv,true), LOGGER_DEBUG);

	$cmd        = $argv[1];
	$item_id    = intval($argv[2]);

	for($x = 3; $x < $argc; $x ++) {

		$contact_id = intval($argv[$x]);

		// Some other process may have delivered this item already.

		$r = q("select * from deliverq where cmd = '%s' and item = %d and contact = %d limit 1",
			dbesc($cmd),
			dbesc($item_id),
			dbesc($contact_id)
		);
		if(! count($r)) {
			continue;
		}	

		$maxsysload = intval(get_config('system','maxloadavg'));
		if($maxsysload < 1)
			$maxsysload = 50;
		if(function_exists('sys_getloadavg')) {
			$load = sys_getloadavg();
			if(intval($load[0]) > $maxsysload) {
				logger('system: load ' . $load . ' too high. Delivery deferred to next queue run.');
				return;
			}
		}

		// It's ours to deliver. Remove it from the queue.
		// should probably set to "pending" or some such

		q("delete from deliverq where cmd = '%s' and item = %d and contact = %d limit 1",
			dbesc($cmd),
			dbesc($item_id),
			dbesc($contact_id)
		);

		if((! $item_id) || (! $contact_id))
			continue;

		$expire = false;
		$top_level = false;
		$recipients = array();
		$url_recipients = array();

		$normal_mode = true;

		$recipients[] = $contact_id;

		if($cmd === 'expire') {
			$normal_mode = false;
			$expire = true;
			$items = q("SELECT * FROM `item` WHERE `uid` = %d AND `wall` = 1 
				AND `deleted` = 1 AND `changed` > UTC_TIMESTAMP() - INTERVAL 30 MINUTE",
				intval($item_id)
			);
			$uid = $item_id;
			$item_id = 0;
			if(! count($items))
			continue;
		}
		else {

			// find ancestors
			$r = q("SELECT * FROM `item` WHERE `id` = %d and visible = 1 and moderated = 0 LIMIT 1",
				intval($item_id)
			);

			if((! count($r)) || (! intval($r[0]['parent']))) {
				continue;
			}

			$target_item = $r[0];
			$parent_id = intval($r[0]['parent']);
			$uid = $r[0]['uid'];
			$updated = $r[0]['edited'];

			// POSSIBLE CLEANUP --> The following seems superfluous. We've already checked for "if (! intval($r[0]['parent']))" a few lines up
			if(! $parent_id)
				continue;


			$items = q("SELECT `item`.*, `sign`.`signed_text`,`sign`.`signature`,`sign`.`signer` 
				FROM `item` LEFT JOIN `sign` ON `sign`.`iid` = `item`.`id` WHERE `parent` = %d and visible = 1 and moderated = 0 ORDER BY `id` ASC",
				intval($parent_id)
			);

			if(! count($items)) {
				continue;
			}

			$icontacts = null;
			$contacts_arr = array();
			foreach($items as $item)
				if(! in_array($item['contact-id'],$contacts_arr))
					$contacts_arr[] = intval($item['contact-id']);
			if(count($contacts_arr)) {
				$str_contacts = implode(',',$contacts_arr); 
				$icontacts = q("SELECT * FROM `contact` 
					WHERE `id` IN ( $str_contacts ) "
				);
			}
			if( ! ($icontacts && count($icontacts)))
				continue;

			// avoid race condition with deleting entries

			if($items[0]['deleted']) {
				foreach($items as $item)
					$item['deleted'] = 1;
			}

			if((count($items) == 1) && ($items[0]['uri'] === $items[0]['parent_uri'])) {
				logger('delivery: top level post');
				$top_level = true;
			}
		}

		$r = q("SELECT `contact`.*, `user`.`pubkey` AS `upubkey`, `user`.`prvkey` AS `uprvkey`, 
			`user`.`timezone`, `user`.`nickname`, `user`.`page-flags`
			FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid` 
			WHERE `contact`.`uid` = %d AND `contact`.`self` = 1 LIMIT 1",
			intval($uid)
		);

		if(! count($r))
			continue;

		$owner = $r[0];

		$walltowall = ((($top_level) && ($owner['id'] != $items[0]['contact-id'])) ? true : false);

		$public_message = true;

		// fill this in with a single salmon slap if applicable

		$slap = '';

		require_once('include/group.php');

		$parent = $items[0];

			// This is IMPORTANT!!!!

			// We will only send a "notify owner to relay" or followup message if the referenced post
			// originated on our system by virtue of having our hostname somewhere
			// in the URI, AND it was a comment (not top_level) AND the parent originated elsewhere.
			// if $parent['wall'] == 1 we will already have the parent message in our array
			// and we will relay the whole lot.
 	
			// expire sends an entire group of expire messages and cannot be forwarded.
			// However the conversation owner will be a part of the conversation and will 
			// be notified during this run.
			// Other DFRN conversation members will be alerted during polled updates.

		$localhost = $a->get_hostname();
		if(strpos($localhost,':'))
			$localhost = substr($localhost,0,strpos($localhost,':'));

		/**
		 *
		 * Be VERY CAREFUL if you make any changes to the following line. Seemingly innocuous changes 
		 * have been known to cause runaway conditions which affected several servers, along with 
		 * permissions issues. 
		 *
		 */
 
		if((! $top_level) && ($parent['wall'] == 0) && (! $expire) && (stristr($target_item['uri'],$localhost))) {
			logger('relay denied for delivery agent.');

			/* no relay allowed for direct contact delivery */
			continue;
		}

		if((strlen($parent['allow_cid'])) 
			|| (strlen($parent['allow_gid'])) 
			|| (strlen($parent['deny_cid'])) 
			|| (strlen($parent['deny_gid']))) {
			$public_message = false; // private recipients, not public
		}

		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `blocked` = 0 AND `pending` = 0",
			intval($contact_id)
		);

		if(count($r))
			$contact = $r[0];
	
		$hubxml = feed_hublinks();

		logger('notifier: slaps: ' . print_r($slaps,true), LOGGER_DATA);

		require_once('include/salmon.php');

		if($contact['self'])
			continue;

		$deliver_status = 0;

		switch($contact['network']) {

			case NETWORK_DFRN :
				logger('notifier: dfrndelivery: ' . $contact['name']);

				$feed_template = get_markup_template('atom_feed.tpl');
				$mail_template = get_markup_template('atom_mail.tpl');

				$atom = '';


				$birthday = feed_birthday($owner['uid'],$owner['timezone']);

				if(strlen($birthday))
					$birthday = '<dfrn:birthday>' . xmlify($birthday) . '</dfrn:birthday>';

				$atom .= replace_macros($feed_template, array(
						'$version'      => xmlify(FRIENDICA_VERSION),
						'$feed_id'      => xmlify($a->get_baseurl() . '/profile/' . $owner['nickname'] ),
						'$feed_title'   => xmlify($owner['name']),
						'$feed_updated' => xmlify(datetime_convert('UTC', 'UTC', $updated . '+00:00' , ATOM_TIME)) ,
						'$hub'          => $hubxml,
						'$salmon'       => '',  // private feed, we don't use salmon here
						'$name'         => xmlify($owner['name']),
						'$profile_page' => xmlify($owner['url']),
						'$photo'        => xmlify($owner['photo']),
						'$thumb'        => xmlify($owner['thumb']),
						'$picdate'      => xmlify(datetime_convert('UTC','UTC',$owner['avatar-date'] . '+00:00' , ATOM_TIME)) ,
						'$uridate'      => xmlify(datetime_convert('UTC','UTC',$owner['uri-date']    . '+00:00' , ATOM_TIME)) ,
						'$namdate'      => xmlify(datetime_convert('UTC','UTC',$owner['name-date']   . '+00:00' , ATOM_TIME)) ,
						'$birthday'     => $birthday,
						'$community'    => (($owner['page-flags'] == PAGE_COMMUNITY) ? '<dfrn:community>1</dfrn:community>' : '')
				));

				foreach($items as $item) {
					if(! $item['parent'])
						continue;

					// private emails may be in included in public conversations. Filter them.
					if(($public_message) && $item['private'] == 1)
						continue;

					$item_contact = get_item_contact($item,$icontacts);
					if(! $item_contact)
						continue;

					if($normal_mode) {
						if($item_id == $item['id'] || $item['id'] == $item['parent'])
							$atom .= atom_entry($item,'text',null,$owner,true,(($top_level) ? $contact['id'] : 0));
					}
					else
						$atom .= atom_entry($item,'text',null,$owner,true);

				}

				$atom .= '</feed>' . "\r\n";
	
				logger('notifier: ' . $atom, LOGGER_DATA);
				$basepath =  implode('/', array_slice(explode('/',$contact['url']),0,3));

				// perform local delivery if we are on the same site

				if(link_compare($basepath,$a->get_baseurl())) {

					$nickname = basename($contact['url']);
					if($contact['issued-id'])
						$sql_extra = sprintf(" AND `dfrn-id` = '%s' ", dbesc($contact['issued-id']));
					else
						$sql_extra = sprintf(" AND `issued-id` = '%s' ", dbesc($contact['dfrn-id']));

					$x = q("SELECT	`contact`.*, `contact`.`uid` AS `importer_uid`, 
						`contact`.`pubkey` AS `cpubkey`, 
						`contact`.`prvkey` AS `cprvkey`, 
						`contact`.`thumb` AS `thumb`, 
						`contact`.`url` as `url`,
						`contact`.`name` as `senderName`,
						`user`.* 
						FROM `contact` 
						LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid` 
						WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0
						AND `contact`.`network` = '%s' AND `user`.`nickname` = '%s'
						$sql_extra
						AND `user`.`account_expired` = 0 LIMIT 1",
						dbesc(NETWORK_DFRN),
						dbesc($nickname)
					);

					if(count($x)) {
						if($owner['page-flags'] == PAGE_COMMUNITY && ! $x[0]['writable']) {
							q("update contact set writable = 1 where id = %d limit 1",
								intval($x[0]['id'])
							);
							$x[0]['writable'] = 1;
						}

						$ssl_policy = get_config('system','ssl_policy');
						fix_contact_ssl_policy($x[0],$ssl_policy);

						// If we are setup as a soapbox we aren't accepting input from this person

						if($x[0]['page-flags'] == PAGE_SOAPBOX)
							break;

						require_once('library/simplepie/simplepie.inc');
						logger('mod-delivery: local delivery');
						local_delivery($x[0],$atom);
						break;
					}
				}

				if(! was_recently_delayed($contact['id']))
					$deliver_status = dfrn_deliver($owner,$contact,$atom);
				else
					$deliver_status = (-1);

				logger('notifier: dfrn_delivery returns ' . $deliver_status);

				if($deliver_status == (-1)) {
					logger('notifier: delivery failed: queuing message');
					add_to_queue($contact['id'],NETWORK_DFRN,$atom);
				}
				break;

			default:
				break;
		}
	}

	return;
}

if (array_search(__file__,get_included_files())===0){
  delivery_run($argv,$argc);
  killme();
}
