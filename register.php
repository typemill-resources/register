<?php

namespace Plugins\register;

use \Typemill\Plugin;
use Typemill\Models\Validation;
use Typemill\Models\User;
use Typemill\Models\Write;
use Typemill\Models\WriteYaml;

class Register extends Plugin
{
	protected $pluginSettings;
	protected $originalHtml;
	protected $active = false;
	
    public static function getSubscribedEvents()
    {
		return array(
			'onSettingsLoaded'			=> 'onSettingsLoaded',
			'onCacheUpdated'			=> 'onCacheUpdated',
			'onPageReady'				=> 'onPageReady'
		);
    }

	public static function addNewRoutes()
	{
		return [
			['httpMethod' => 'get', 'route' => '/tm/register', 'class' => 'Plugins\Register\Register:showRegistrationForm', 'name' => 'register.show'],
			['httpMethod' => 'post', 'route' => '/tm/register', 'class' => 'Plugins\Register\Register:createUser', 'name' => 'register.create'],
			['httpMethod' => 'get', 'route' => '/tm/registerhoney', 'class' => 'Plugins\Register\Register:showHoneypot', 'name' => 'register.honeypot'],
			['httpMethod' => 'get', 'route' => '/tm/registerwelcome', 'class' => 'Plugins\Register\Register:showWelcome', 'name' => 'register.welcome'],
			['httpMethod' => 'get', 'route' => '/tm/registeroptin', 'class' => 'Plugins\Register\Register:optin', 'name' => 'register.optin'],
			['httpMethod' => 'get', 'route' => '/tm/registeremail', 'class' => 'Plugins\Register\Register:requestConfirmationEmail', 'name' => 'register.requestemail'],
			['httpMethod' => 'post', 'route' => '/tm/registeremail', 'class' => 'Plugins\Register\Register:sendConfirmationEmailAgain', 'name' => 'register.sendemail'],
		];
	}

	# add the two registration pages to the registersteps
	public function onSettingsLoaded($settings)
	{
		# get settings
		$this->settings = $this->container->get('settings');

		$registersteps = isset($this->settings['registersteps']) ? $this->settings['registersteps'] : [];

		# add the route name as the second step
		$registersteps['register.show'] = 1;
		$registersteps['register.welcome'] = 10;

		# update the settings
		$this->container->get('settings')->replace(['registersteps' => $registersteps]);

	}

	# read last cache time and triggere functions once a day
	public function onCacheUpdated()
	{
		$write 		= new Write();

		$now 		= new \DateTime('NOW');

		# last update is stored in register.txt
		$lastRegisterCheck 	= $write->getFile('cache', 'lastRegister.txt');

		if(!$lastRegisterCheck)
		{
			# send it at night at 4 am
			$now->setTime(4,0);

			# add one day, so it will run next day at 4 in the morning
			$now->add(new \DateInterval('P1D'));

			# write it to lastRegister
			$write->writeFile('cache', 'lastRegister.txt', $now->format("Y-m-d H:i:s"));
		}
		else
		{
			$lastRegisterCheck = new \DateTime($lastRegisterCheck);

			if($lastRegisterCheck <= $now)
			{				
				# write next time to the cached register-check
				$lastRegisterCheck->add(new \DateInterval('P1D'));
				$write->writeFile('cache', 'lastRegister.txt', $lastRegisterCheck->format("Y-m-d H:i:s"));

				$this->checkRegisteredUsers($this->container->assets->baseUrl);
			}

		}
	}

	# check if the mail plugin is active or not
	public function onPageReady($data)
	{
		if(strpos($this->getPath(), 'tm/plugins') !== false)
		{
			$this->settings = $this->getSettings();

			$mailplugin = false;

			if(isset($this->settings['plugins']['mail']) AND $this->settings['plugins']['mail']['active'])
			{
				$mailplugin = true;
			}

			if(!$mailplugin)
			{
				$pagedata = $data->getData();
				$pagedata['messages']['error'] = ['You have to activate the mail-plugin to use the register plugin'];
				$data->setData($pagedata);
			}
		}
	}

	# show the registration form
	public function showRegistrationForm($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		$this->addCSS('/register/css/register.css');

		$twig   = $this->getTwig();  // ge the twig-object
		$loader = $twig->getLoader();  // get the twig-template-loader	
		$loader->addPath(__DIR__ . '/templates');

		$settings = $this->getSettings();
		$recaptcha_webkey = false;

		# get the registersteps, sort them by value (order), add class active or inactive 
		$registersteps = $settings['registersteps'];
		asort($registersteps);
		$class = 'active';
		foreach($registersteps as $key => $step)
		{
			$registersteps[$key] = $class;
			if($key == 'register.show'){ $class = 'inactive'; }
		}

		if(isset($settings['plugins']['register']['recaptcha']) && $settings['plugins']['register']['recaptcha_webkey'] != '')
		{
			$recaptcha_webkey = $settings['plugins']['register']['recaptcha_webkey'];
		}

		return $twig->render($response, '/register.twig', ['settings' => $settings, 'recaptcha_webkey' => $recaptcha_webkey, 'registersteps' => $registersteps]);
	}

	# create a new user
	public function createUser($request, $response, $args)
	{
		$params 		= $request->getParams();
		$settings 		= $this->getSettings();
		$uri 			= $request->getUri()->withUserInfo('');
		$base_url		= $uri->getBaseUrl();

		# simple bot check with honeypot
		if(!$this->checkHoneypot($params))
		{
			return $response->withRedirect($this->container['router']->pathFor('register.honeypot'));
		}
		unset($params['personalMail']);

		# check recaptcha if active
		if(!$this->checkCaptcha($params, $settings))
		{
			$this->container['flash']->addMessage('error', 'Incorrect captcha');
			return $response->withRedirect($this->container['router']->pathFor('register.show'));
		}
		unset($params['g-recaptcha-response']);

		$validate		= new Validation();
		$user			= new User();

		# set member as standard role for user
		$params['userrole'] = 'member';

		# get userroles for validation
		$userroles 		= $this->container['acl']->getRoles();

		# validate user 
		if($validate->newUser($params, $userroles))
		{
			# generate confirmation token 
			$created 		= date("Y-m-d H:i:s");
			$optintoken 	= bin2hex(random_bytes(32));

			$userdata = array(
				'username' 		=> '_' . $params['username'], 
				'email' 		=> $params['email'], 
				'userrole' 		=> $params['userrole'], 
				'password' 		=> $params['password'],
				'created'		=> $created,
				'optintoken'	=> $optintoken
			);

			if(isset($params['legalcheckbox1']))
			{
				$userdata['legalcheckbox1'] = true;
			}
			if(isset($params['legalcheckbox2']))
			{
				$userdata['legalcheckbox2'] = true;
			}
			
			# create user
			$username = $user->createUser($userdata);

			if($username)
			{
				$send = $this->sendConfirmationEmail($settings, $userdata, $base_url);

				if($send !== true)
				{
					$twig   = $this->getTwig();
					$loader = $twig->getLoader();	
					$loader->addPath(__DIR__ . '/templates');

					$this->addCSS('/register/css/register.css');

					return $twig->render($response, '/registererror.twig', ['error' => ['title' => 'Error with Confirmation Mail', 'message' => 'Sorry, something went wrong! We created your user account, but we could not send the confirmation mail with the registration link. You cannot login without this confirmation. Please contact the owner of the website and tell him your username so he can solve the problem.']]);
				}

				# send mail to admin if feature is activated
				if(isset($settings['plugins']['register']['notifyafterregistration']) && $settings['plugins']['register']['notifyafterregistration'])
				{
					$send = $this->sendRegisterNotification($settings, $userdata);
				}

				# check the next registerstep (next route-name)
				$registersteps = $settings['registersteps'];
				asort($registersteps);
				$nextstep = array_keys($registersteps)[1];

				return $response->withRedirect($this->container['router']->pathFor($nextstep));
			}

			$this->container['flash']->addMessage('error', 'We could not create the user, please check if settings folder is writable.');
		}
		else
		{
			$this->container['flash']->addMessage('error', 'Please check your input and try again');
		}

		return $response->withRedirect($this->container['router']->pathFor('register.show'));
  	}


	protected function sendConfirmationEmail($settings, $userdata, $base_url)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		$this->container->dispatcher->dispatch('onTwigLoaded');

		# send confirmation mail
		$send = false; 
					
		if(isset($this->container['mail']))
		{
			$username 	= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];

			# create body lines for html and no html mails
			$body1 		= $settings['plugins']['register']['mailsalutation'] . " " . $username . ",";
			$body2 		= "\n\n" . $settings['plugins']['register']['mailbeforelink'];
			$body3 		= "\n\n" . $base_url . "/tm/registeroptin?optintoken=" . $userdata['optintoken'] . "&username=" . $username;
			$body3html 	= "\n\n" . "[Registration Link](" . $base_url . "/tm/registeroptin?optintoken=" . $userdata['optintoken'] . "&username=" . $username . ")";
			$body4 		= "\n\n" . $settings['plugins']['register']['mailafterlink'];

			# body without html
			$body 		= $body1 . $body2 . $body3 . $body4;
					
			# body with html
			$bodyhtml 	= $body1 . $body2 . $body3html . $body4;
			$bodyhtml 	= $this->markdownToHtml($bodyhtml);

			# setup and send mail
			$mail = $this->container['mail'];
			$mail->ClearAllRecipients();

			$mail->addAdress($userdata['email']);
			$mail->addReplyTo($settings['plugins']['register']['mailreplyto'], $settings['plugins']['register']['mailreplytoname']);
			$mail->setSubject($settings['plugins']['register']['mailsubject']);
			$mail->setBody($bodyhtml);
			$mail->setAltBody($body);

			$send = $mail->send();
		}
	
		return $send;
	}


	# show page to send confirmation email again
	public function requestConfirmationEmail($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		$settings 			= $this->getSettings();


		$twig   			= $this->getTwig();  // get the twig-object
		$loader 			= $twig->getLoader();  // get the twig-template-loader	
		$loader->addPath(__DIR__ . '/templates');

		$this->addCSS('/register/css/register.css');

		$recaptcha_webkey	= false;
		if(isset($settings['plugins']['register']['recaptcha']) && $settings['plugins']['register']['recaptcha_webkey'] != '')
		{
			$recaptcha_webkey = $settings['plugins']['register']['recaptcha_webkey'];
		}

		return $twig->render($response, '/registeremail.twig', ['settings' => $settings, 'recaptcha_webkey' => $recaptcha_webkey]);
	}

	# send the confirmation email again
	public function sendConfirmationEmailAgain($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		$params 		= $request->getParams();
		$settings 		= $this->getSettings();
		$uri 			= $request->getUri()->withUserInfo('');
		$base_url		= $uri->getBaseUrl();

		# simple bot check with honeypot
		if(!$this->checkHoneypot($params))
		{
			return $response->withRedirect($this->container['router']->pathFor('register.honeypot'));
		}
		unset($params['personalMail']);

		# check recaptcha if active
		if(!$this->checkCaptcha($params, $settings))
		{
			$this->container['flash']->addMessage('error', 'Incorrect captcha');
			return $response->withRedirect($this->container['router']->pathFor('register.requestemail'));
		}
		unset($params['g-recaptcha-response']);

		# check if input is valid email
		$validate		= new Validation();
		$validator 		= $validate->returnValidator($params);
		$validator->rule('required', ['confirmationmail']);
		$validator->rule('email', 'confirmationmail');
		if(!$validator->validate())
		{
			$this->container['flash']->addMessage('error', 'Please enter a valid email.');
			return $response->withRedirect($this->container['router']->pathFor('register.requestemail'));
		}

		$user			= new User();

		# this searches over all existing users. You can improve performance with a separate function that only checks users starting with _
		$registeredUser = $user->findUserByEmail($params['confirmationmail']);

		if(!$registeredUser)
		{
			$this->container['flash']->addMessage('error', 'We did not find a user with a valid optin token.');

			return $response->withRedirect($this->container['router']->pathFor('register.requestemail'));
		}

		if(!isset($registeredUser['optintoken']) OR !$registeredUser['optintoken'])
		{
			$this->container['flash']->addMessage('error', 'We did not find a user with a valid optin token.');

			return $response->withRedirect($this->container['router']->pathFor('register.requestemail'));
		}

		$send = $this->sendConfirmationEmail($settings, $registeredUser, $base_url);

		if($send !== true)
		{
			$twig   = $this->getTwig();
			$loader = $twig->getLoader();	
			$loader->addPath(__DIR__ . '/templates');

			$this->addCSS('/register/css/register.css');

			return $twig->render($response, '/registererror.twig', ['error' => ['title' => 'Error With Confirmation Email', 'message' => 'Sorry, something went wrong! We created your user account, but we could not send the confirmation mail with the registration link. You cannot login without this confirmation. Please contact the owner of the website and tell him your username so he can solve the problem.']]);
		}

		return $response->withRedirect($this->container['router']->pathFor('register.welcome'));
	}

	# check registered but unconfirmed users, send mail or delete. Triggered once a day by pseudo-cronjob
	private function checkRegisteredUsers($baseUrl)
	{		
		$userDir = __DIR__ . '/../../settings/users';
				
		# check if users directory exists 
		if(!is_dir($userDir)){ return array(); }
		
		# get interval for reminder
		$remind = ( isset($this->settings['plugins']['register']['reminduser']) ) ? $this->settings['plugins']['register']['reminduser'] : 5;
		$remind = 'P'.$remind.'D';

		# get interval for delete
		$delete = ( isset($this->settings['plugins']['register']['deleteuser']) ) ? $this->settings['plugins']['register']['deleteuser'] : 5;
		$delete = 'P'.$delete.'D';

		# get all user files 
		$users = array_diff(scandir($userDir), array('..', '.'));
		
		$userModel = new User();

		foreach($users as $key => $user)
		{
			if($user[0] == '_')
			{
				$user = str_replace('.yaml', '', $user);

				$userdata 		= $userModel->getUser($user);

				# the created as DateTime
				$created 		= new \DateTime($userdata['created']);

				# the time right now
				$now 			= new \DateTime('NOW');
				$nowFormat		= $now->format('Y-m-d');

				$created->add(new \DateInterval($remind));
				$rememberuser 	= $created->format("Y-m-d");

				$created->add(new \DateInterval($delete));
				$deleteuser 	= $created->format("Y-m-d");

				# if you have not a single visit on your page that day, then this won't work
				if($rememberuser == $nowFormat)
				{
					$send = $this->sendConfirmationEmail($this->settings, $userdata, $baseUrl);

					$userdata['optinreminder'] = $now->format('Y-m-d H:i:s');

					if($send !== true)
					{
						$userdata['optinreminder'] .= ' Could not send email.';
					}

					# update the user with the reminder date
					$userModel->updateUser($userdata);
				}

				if($deleteuser <= $nowFormat)
				{
					$userModel->deleteUser($user);
				}
			}
		}
	}

	# simple honeypot check
	private function checkHoneypot($params)
	{
		# simple bot check with honeypot
		if(isset($params['personalMail']))
		{
			if($params['personalMail'] != '')
			{
				return false;
			}
		}

		return true;
	}

	# google recaptcha check
	private function checkCaptcha($params, $settings)
	{
		if(isset($params['g-recaptcha-response']))
		{
			$recaptchaApi 		= 'https://www.google.com/recaptcha/api/siteverify';
			$secret				= isset($settings['plugins']['register']['recaptcha_secretkey']) ? $settings['plugins']['register']['recaptcha_secretkey'] : false;
			$recaptchaRequest 	= ['secret' => $secret, 'response' => $params['g-recaptcha-response']];

			# use key 'http' even if you send the request to https://...
			$options = array(
				'http' => array(
					'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
					'method'  => 'POST',
					'content' => http_build_query($recaptchaRequest),
					'timeout' => 5
				)
			);

			$context  	= stream_context_create($options);
			$result 	= file_get_contents($recaptchaApi, false, $context);
			$result		= json_decode($result);
				
			if ($result === FALSE || $result->success === FALSE)
			{
				return false;
			}

		}
		return true;
	}

	# show honeypot page if you think it is a bot (filled out the honeypot field)
	public function showHoneypot($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		$twig   = $this->getTwig();
		$loader = $twig->getLoader();	
		$loader->addPath(__DIR__ . '/templates');

		$this->addCSS('/register/css/register.css');
		
		return $twig->render($response, '/honeypot.twig', ['settings' => $this->getSettings()]);
	}

	# show welcome page after successful registration
	public function showWelcome($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		$settings = $this->getSettings();

		# get the registersteps, sort them by value (order), add class active or inactive 
		$registersteps = $settings['registersteps'];
		asort($registersteps);
		$class = 'active';
		foreach($registersteps as $key => $step)
		{
			$registersteps[$key] = $class;
			if($key == 'register.welcome'){ $class = 'inactive'; }
		}

		$twig   = $this->getTwig();  // get the twig-object
		$loader = $twig->getLoader();  // get the twig-template-loader	
		$loader->addPath(__DIR__ . '/templates');

		$this->addCSS('/register/css/register.css');

		return $twig->render($response, '/registerwelcome.twig', ['settings' => $settings, 'registersteps' => $registersteps]);
	}

	# show page after user confirmed registration with the optin link
	public function optin($request, $response, $args)
	{
		$params = $request->getParams();

		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $response->withRedirect($this->container['router']->pathFor('user.account'));
		}

		if(!isset($params['optintoken']) OR !isset($params['username']))
		{
			$this->container['flash']->addMessage('error', 'We could not confirm your account (missing data). Please try again or contact the administrator.');
			return $response->withRedirect($this->container['router']->pathFor('auth.show'));
		}

		# validate token format
		if(!strlen($params['optintoken']) == 64 OR !ctype_alnum($params['optintoken']))
		{
			$this->container['flash']->addMessage('error', 'We could not confirm your account (wrong data). Please contact the administrator.');
			return $response->withRedirect($this->container['router']->pathFor('auth.show'));
		}

		$settings = $this->getSettings();

		$yaml = new WriteYaml();

		$optinuser = $yaml->getYaml('settings' . DIRECTORY_SEPARATOR . 'users', '_' . $params['username'] . '.yaml');

		if(!$optinuser)
		{
			$this->container['flash']->addMessage('error', 'We did not find that user. Please try again or contact the administrator.');
			return $response->withRedirect($this->container['router']->pathFor('auth.show'));
		}

		if($optinuser['optintoken'] != $params['optintoken'])
		{
			$this->container['flash']->addMessage('error', 'We could not confirm your account (wrong token). Please try again or contact the administrator.');
			return $response->withRedirect($this->container['router']->pathFor('auth.show'));
		}

		$optinuser['username'] 		= ($optinuser['username'][0] == '_') ? ltrim($optinuser['username'], '_') : $optinuser['username'];
		$optinuser['optintoken'] 	= false;

		$yaml->updateYaml('settings' . DIRECTORY_SEPARATOR . 'users', '_' . $optinuser['username'] . '.yaml', $optinuser);
		$yaml->renameFile('settings' . DIRECTORY_SEPARATOR . 'users', '_' . $optinuser['username'] . '.yaml', $optinuser['username'] . '.yaml');

		# send confirmation notification to admin, if activated
		if(isset($settings['plugins']['register']['notifyafterconfirmation']) && $settings['plugins']['register']['notifyafterconfirmation'])
		{
			$send = $this->sendConfirmationNotification($settings, $optinuser['username']);
		}

		$this->container['flash']->addMessage('info', 'Your account is confirmed now. Please login.');
		return $response->withRedirect($this->container['router']->pathFor('auth.show'));
	}

	protected function sendRegisterNotification($settings, $userdata)
	{
		# do not dispatch twig here because it has been dispatched already

		# send confirmation mail
		$send = false; 
					
		if(isset($this->container['mail']))
		{
			$username 	= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];

			# create body lines for html and no html mails
			$body 		= "The new user " . $username . " has registered. We are waiting for the confirmation now.";

			# setup and send mail
			$mail = $this->container['mail'];
			$mail->ClearAllRecipients();

			$mail->addAdress($settings['plugins']['register']['mailreplyto']);
			$mail->setSubject("New user: " . $username);
			$mail->setBody($body);
			$mail->setAltBody($body);

			$send = $mail->send();
		}
	
		return $send;
	}

	protected function sendConfirmationNotification($settings, $username)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		$this->container->dispatcher->dispatch('onTwigLoaded');

		# send confirmation mail
		$send = false; 
					
		if(isset($this->container['mail']))
		{
			# create body lines for html and no html mails
			$body 		= "The new user " . $username . " has confirmed his account.";

			# setup and send mail
			$mail = $this->container['mail'];
			$mail->ClearAllRecipients();

			$mail->addAdress($settings['plugins']['register']['mailreplyto']);
			$mail->setSubject("New user: " . $username);
			$mail->setBody($body);
			$mail->setAltBody($body);

			$send = $mail->send();
		}
	
		return $send;
	}
}