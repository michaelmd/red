<?php


function zentity_init(&$a) {
	$a->page['template'] = 'full';

	$cmd = ((argc() > 1) ? argv(1) : '');


	if($cmd === 'autofill.json') {
		require_once('library/urlify/URLify.php');
		$result = array('error' => false, 'message' => '');
		$n = trim($_REQUEST['name']);

		$x = strtolower(URLify::transliterate($n));

		$test = array();

		// first name
		$test[] = legal_webbie(substr($x,0,strpos($x,' ')));
		if($test[0]) {
			// first name plus first initial of last
			$test[] = ((strpos($x,' ')) ? $test[0] . legal_webbie(trim(substr($x,strpos($x,' '),2))) : '');
			// first name plus random number
			$test[] = $test[0] . mt_rand(1000,9999);
		}
		// fullname
		$test[] = legal_webbie($x);
		// fullname plus random number
		$test[] = legal_webbie($x) . mt_rand(1000,9999);

		json_return_and_die(check_webbie($test));
	}

	if($cmd === 'checkaddr.json') {
		require_once('library/urlify/URLify.php');
		$result = array('error' => false, 'message' => '');
		$n = trim($_REQUEST['addr']);

		$x = strtolower(URLify::transliterate($n));

		$test = array();

		$n = legal_webbie($x);
		if(strlen($n)) {
			$test[] = $n;
			$test[] = $n . mt_rand(1000,9999);
		}

		for($y = 0; $y < 100; $y ++)
			$test[] = 'id' . mt_rand(1000,9999);

//print_r($test);

		json_return_and_die(check_webbie($test));
	}




//		print_r($test);

//		if(! allowed_email($email))
//			$result['message'] = t('Your email domain is not among those allowed on this site');
//		if((! valid_email($email)) || (! validate_email($email)))
//			$result['message'] .= t('Not a valid email address') . EOL;
//		if($result['message'])
//			$result['error'] = true;

//		header('content-type: application/json');
//		echo json_encode($result);
//		killme();		


	$pw1 = t("Password too short");
	$pw2 = t("Passwords do not match");

	$a->page['htmlhead'] .= <<< EOT
<script>
	function zFormError(elm,x) {
		if(x) {
			$(elm).addClass("zform-error");
			$(elm).removeClass("zform-ok");
		}
		else {
			$(elm).addClass("zform-ok");
			$(elm).removeClass("zform-error");
		}											
	}
	$(document).ready(function() {
		$("#zregister-email").blur(function() {
			var zreg_email = $("#zregister-email").val();
			$.get("zregister/email_check.json?f=&email=" + encodeURIComponent(zreg_email),function(data) {
				$("#zregister-email-feedback").html(data.message);
				zFormError("#zregister-email-feedback",data.error);
			});
		});
		$("#zregister-password").blur(function() {
			if(($("#zregister-password").val()).length < 6 ) {
				$("#zregister-password-feedback").html("$pw1");
				zFormError("#zregister-password-feedback",true);
			}
			else {
				$("#zregister-password-feedback").html("");
				zFormError("#zregister-password-feedback",false);
			}
		});
		$("#zregister-password2").blur(function() {
			if($("#zregister-password").val() != $("#zregister-password2").val()) {
				$("#zregister-password2-feedback").html("$pw2");
				zFormError("#zregister-password2-feedback",true);
			}
			else {
				$("#zregister-password2-feedback").html("");
				zFormError("#zregister-password2-feedback",false);
			}
		});
	});

</script>

EOT;

}


function zentity_post(&$a) {

	$verified = 0;
	$blocked  = 1;

	$arr = array('post' => $_POST);
	call_hooks('zregister_post', $arr);

	$max_dailies = intval(get_config('system','max_daily_registrations'));
	if($max_dailies) {
		$r = q("select count(*) as total from account where account_created > UTC_TIMESTAMP - INTERVAL 1 day");
		if($r && $r[0]['total'] >= $max_dailies) {
			return;
		}
	}

	switch(get_config('system','register_policy')) {

	case REGISTER_OPEN:
		$blocked = 0;
		$verified = 0;
		break;

	case REGISTER_APPROVE:
		$blocked = 0;
		$verified = 0;
		break;

	default:
	case REGISTER_CLOSED:
		// TODO check against service class and fix this line
		if((! x($_SESSION,'authenticated') && (! x($_SESSION,'administrator')))) {
			notice( t('Permission denied.') . EOL );
			return;
		}
		$blocked = 1;
		$verified = 0;
		break;
	}

	require_once('include/account.php');

	$arr = $_POST;

	$arr['blocked'] = $blocked;
	$arr['verified'] = $verified;

	$result = create_account($arr);

	if(! $result['success']) {
		notice($result['message']);
		return;
	}

	$user = $result['user'];
 
	$using_invites = get_config('system','invitation_only');
	$num_invites   = get_config('system','number_invites');
	$invite_id  = ((x($_POST,'invite_id'))  ? notags(trim($_POST['invite_id']))  : '');


	if( $a->config['register_policy'] == REGISTER_OPEN ) {

		if($using_invites && $invite_id) {
			q("delete * from register where hash = '%s' limit 1", dbesc($invite_id));
			set_pconfig($user['uid'],'system','invites_remaining',$num_invites);
		}

		$email_tpl = get_intltext_template("register_open_eml.tpl");
		$email_tpl = replace_macros($email_tpl, array(
				'$sitename' => $a->config['sitename'],
				'$siteurl' =>  $a->get_baseurl(),
				'$username' => $user['username'],
				'$email' => $user['email'],
				'$password' => $result['password'],
				'$uid' => $user['uid'] ));

		$res = mail($user['email'], sprintf(t('Registration details for %s'), $a->config['sitename']),
			$email_tpl, 
				'From: ' . t('Administrator') . '@' . $_SERVER['SERVER_NAME'] . "\n"
				. 'Content-type: text/plain; charset=UTF-8' . "\n"
				. 'Content-transfer-encoding: 8bit' );


		if($res) {
			info( t('Registration successful. Please check your email for further instructions.') . EOL ) ;
			goaway(z_root());
		}
	}
	elseif($a->config['register_policy'] == REGISTER_APPROVE) {

		if(! strlen($a->config['admin_email'])) {
			notice( t('Your registration can not be processed.') . EOL);
			goaway(z_root());
		}

		$hash = random_string();
		$r = q("INSERT INTO `register` ( `hash`, `created`, `uid`, `password`, `language` ) VALUES ( '%s', '%s', %d, '%s', '%s' ) ",
			dbesc($hash),
			dbesc(datetime_convert()),
			intval($user['uid']),
			dbesc($result['password']),
			dbesc($a->language)
		);

		$r = q("SELECT `language` FROM `user` WHERE `email` = '%s' LIMIT 1",
			dbesc($a->config['admin_email'])
		);
		if(count($r))
			push_lang($r[0]['language']);
		else
			push_lang('en');

		if($using_invites && $invite_id) {
			q("delete * from register where hash = '%s' limit 1", dbesc($invite_id));
			set_pconfig($user['uid'],'system','invites_remaining',$num_invites);
		}

		$email_tpl = get_intltext_template("register_verify_eml.tpl");
		$email_tpl = replace_macros($email_tpl, array(
				'$sitename' => $a->config['sitename'],
				'$siteurl' =>  $a->get_baseurl(),
				'$username' => $user['username'],
				'$email' => $user['email'],
				'$password' => $result['password'],
				'$uid' => $user['uid'],
				'$hash' => $hash
		 ));

		$res = mail($a->config['admin_email'], sprintf(t('Registration request at %s'), $a->config['sitename']),
			$email_tpl,
				'From: ' . t('Administrator') . '@' . $_SERVER['SERVER_NAME'] . "\n"
				. 'Content-type: text/plain; charset=UTF-8' . "\n"
				. 'Content-transfer-encoding: 8bit' );

		pop_lang();

		if($res) {
			info( t('Your registration is pending approval by the site owner.') . EOL ) ;
			goaway(z_root());
		}

	}

	return;
}







function zentity_content(&$a) {


	if((! local_user()) && ($a->config['register_policy'] == REGISTER_CLOSED)) {
		notice("Permission denied." . EOL);
		return;
	}

	$max_dailies = intval(get_config('system','max_daily_registrations'));
	if($max_dailies) {
		$r = q("select count(*) as total from account where account_created > UTC_TIMESTAMP - INTERVAL 1 day");
		if($r && $r[0]['total'] >= $max_dailies) {
			logger('max daily registrations exceeded.');
			notice( t('This site has exceeded the number of allowed daily account registrations. Please try again tomorrow.') . EOL);
			return;
		}
	}

	// Configurable terms of service link

	$tosurl = get_config('system','tos_url');
	if(! $tosurl)
		$tosurl = $a->get_baseurl() . '/help/TermsOfService';

	$toslink = '<a href="' . $tosurl . '" >' . t('Terms of Service') . '</a>';

	// Configurable whether to restrict age or not - default is based on international legal requirements
	// This can be relaxed if you are on a restricted server that does not share with public servers

	if(get_config('system','no_age_restriction')) 
		$label_tos = sprintf( t('I accept the %s for this website'), $toslink);
	else
		$label_tos = t('Check this box to import an existing identity file from another location');


	$email        = ((x($_REQUEST,'email'))        ? $_REQUEST['email']        :  "" );
	$password     = ((x($_REQUEST,'password'))     ? $_REQUEST['password']     :  "" );
	$password2    = ((x($_REQUEST,'password2'))    ? $_REQUEST['password2']    :  "" );
	$invite_code  = ((x($_REQUEST,'invite_code'))  ? $_REQUEST['invite_code']  :  "" );



	$o = replace_macros(get_markup_template('zentity.tpl'), array(

		'$title'        => t('Create Identity'),
		'$registertext' => t('An identity is a profile container for a personal profile, blog, public or private group/forum, celebrity page, and more.<br />You may create as many of these as your hub provider allows.'),
		'$invitations'  => get_config('system','invitation_only'),
		'$invite_desc'  => t('Membership on this site is by invitation only.'),
		'$label_invite' => t('Please enter your invitation code'),
		'$invite_id'    => $invite_id,

		'$label_email'  => t('Full name'),
		'$label_pass1'  => t('Choose a short nickname'),
		'$label_pass2'  => t('Your nickname will be used to create an easily remembered web address ("webbie") for your profile.'),
		'$label_tos'    => $label_tos,
	
		'$email'        => $email,
		'$pass1'        => $password,
		'$pass2'        => $password2,
		'$submit'       => t('Create')
	));

	return $o;

}

