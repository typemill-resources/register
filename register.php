<?php

namespace Plugins\register;

use \Typemill\Plugin;
use Typemill\Models\Validation;
use Typemill\Models\User;
use Typemill\Models\Write;
use Typemill\Models\WriteYaml;
use Typemill\Events\OnUserConfirmed;
use Typemill\Events\OnUserDeleted;


class Register extends Plugin
{	
	protected $settings;

    public static function getSubscribedEvents()
    {
		return array(
			'onSettingsLoaded'			=> 'onSettingsLoaded',
			'onPageReady'				=> 'onPageReady',
		);
    }

	public static function addNewRoutes()
	{
		return [
			['httpMethod' => 'get', 'route' => '/tm/register', 'class' => 'Plugins\Register\Register:showRegistrationForm', 'name' => 'register.show'],
			['httpMethod' => 'post', 'route' => '/tm/register', 'class' => 'Plugins\Register\Register:createUser', 'name' => 'register.create'],
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


	# read last cache time and trigger functions once a day
	public function onPageReady()
	{
		$write 		= new Write();

		$now 		= new \DateTime('NOW');

		# last update is stored in register.txt
		$lastRegisterCheck 	= $write->getFile('cache', 'lastRegister.txt');

		if($lastRegisterCheck)
		{
			$lastRegisterCheck = new \DateTime($lastRegisterCheck);
		}

		if(!$lastRegisterCheck OR ($lastRegisterCheck <= $now))
		{
			# send it at night at 4 am
			$now->setTime(5,0);

			# add one day, so it will run next day at 4 in the morning
			$now->add(new \DateInterval('P1D'));

			# write it to lastRegister
			$write->writeFile('cache', 'lastRegister.txt', $now->format("Y-m-d H:i:s"));

			$this->checkRegisteredUsers($this->container->assets->baseUrl);
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

		# get the public forms for the plugin
		$registerform = $this->generateForm('register', 'register.create');

		# get the registersteps, sort them by value (order), add class active or inactive 
		$registersteps = $settings['registersteps'];
		asort($registersteps);
		$class = 'active';
		foreach($registersteps as $key => $step)
		{
			$registersteps[$key] = $class;
			if($key == 'register.show'){ $class = 'inactive'; }
		}

		return $twig->render($response, '/register.twig', ['settings' => $settings, 'registerform' => $registerform, 'registersteps' => $registersteps, ]);
	}

	# create a new user
	public function createUser($request, $response, $args)
	{		
		$params = $this->validateParams($request->getParams());

		if(!$params)
		{
			return $response->withRedirect($this->container['router']->pathFor('register.show'));
		}

		# username, email and password are required, make sure they are there and correctly defined in plugin
		if(!isset($params['username']) OR !isset($params['email']) OR !isset($params['password']))
		{
			$this->container->flash->addMessage('error', 'The fields username, email and password are required. Maybe the plugin is misconfigured.');
			return $response->withRedirect($this->container['router']->pathFor('register.show'));
		}

		if($this->isBurnerEmail($params['email']))
		{
			return $response->withRedirect($this->container['router']->pathFor('home'));
		}

		$settings 		= $this->getSettings();
		$uri 			= $request->getUri()->withUserInfo('');
		$base_url		= $uri->getBaseUrl();
		$validate 		= new Validation();
		$user			= new User();

		# check gumroad license
		if(isset($settings['plugins']['register']['gumroadpermalink']) && $settings['plugins']['register']['gumroadpermalink'] != '')
		{
			if(!isset($params['gumroad']) OR $params['gumroad'] == '')
			{
				$this->container['flash']->addMessage('error', 'Missing Gumroad License Key');
				return $response->withRedirect($this->container['router']->pathFor('register.show'));
			}

		    if  (in_array  ('curl', get_loaded_extensions()))
		    {
				$gumroad_curl = curl_init();

				curl_setopt($gumroad_curl, CURLOPT_URL,"https://api.gumroad.com/v2/licenses/verify");
				curl_setopt($gumroad_curl, CURLOPT_POST, 1);
				curl_setopt(
					$gumroad_curl, 
					CURLOPT_POSTFIELDS,
					"product_permalink=" 
						. $settings['plugins']['register']['gumroadpermalink'] 
						. "&license_key=" . $params['gumroad']
				);

				curl_setopt($gumroad_curl, CURLOPT_RETURNTRANSFER, true);

				$gumroad_curl_result = curl_exec($gumroad_curl);

				curl_close($gumroad_curl);

				$gumroad_result_json = json_decode($gumroad_curl_result);

				if($gumroad_result_json->success != 'true'){
					$this->container['flash']->addMessage('error', 'Incorrect Gumroad License Key');
					return $response->withRedirect($this->container['router']->pathFor('register.show'));
				}
		    }
		    else
		    {
				# make POST request to gumroad API with php stream

				$postdata = http_build_query(
					array(
						'product_permalink' => $settings['plugins']['register']['gumroadpermalink'],
						'license_key' => $params['gumroad']
					)
				);

				$options = array (
	        		'http' => array (
	            		'method' 	=> 'POST',
	       				'ignore_errors' => true,
	            		'header'	=> 	"Content-Type: application/x-www-form-urlencoded\r\n" .
										"Accept: application/json\r\n" .
										"Connection: close\r\n",
	            		'content' 	=> $postdata
					)
	        	);

				$context = stream_context_create($options);

				$gumroad_response = file_get_contents('https://api.gumroad.com/v2/licenses/verify', false, $context);

				$gumroad_result_json = json_decode($gumroad_response,true);

				if(!isset($gumroad_result_json['success']) OR $gumroad_result_json['success'] != 'true')
				{
					$this->container['flash']->addMessage('error', 'Incorrect Gumroad License Key');
					return $response->withRedirect($this->container['router']->pathFor('register.show'));
				}
			}		
		}

		# set member as standard role for user
		$params['userrole'] = 'member';

		# check if another user role has been selected in the plugin configurations
		if(isset($settings['plugins']['register']['userrole']) && $settings['plugins']['register']['userrole'] != '')
		{
			$params['userrole'] = $settings['plugins']['register']['userrole'];
		}

		# get userroles for validation
		$userroles 		= $this->container['acl']->getRoles();

		# validate user 
		if($validate->newUser($params, $userroles))
		{
			# generate confirmation token 
			$created 		= date("Y-m-d H:i:s");
			$optintoken 	= bin2hex(random_bytes(32));

			$userdata 				= $params;
			$userdata['username']	= '_' . $params['username'];
			$userdata['created']	= $created;
			$userdata['optintoken']	= $optintoken;
			
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

				# we need a temporary entry in the session for the subscriber plugin
				$_SESSION['tmp_user'] = $username;

				# check the next registerstep (next route-name)
				$registersteps = $settings['registersteps'];
				asort($registersteps);

				$keys 	= array_keys($registersteps);
				$index 	= array_search("register.show",$keys);
				$next 	= $index+1;
				
				$nextstep = 'register.welcome';
				if(isset($keys[$next]))
				{
					$nextstep = $keys[$next];
				}

				return $response->withRedirect($this->container['router']->pathFor($nextstep));
			}

			$this->container['flash']->addMessage('error', 'We could not create the user, please check if settings folder is writable.');
		}
		else
		{
			if(isset($_SESSION['errors']))
			{
				# we have to fix the error reporting here, because standard user-validation returns error without pluginname (not visible in form then)
				$errors = $_SESSION['errors'];
				unset($_SESSION['errors']);
				$_SESSION['errors']['register'] = $errors; 
			}
			$this->container['flash']->addMessage('error', 'Please check your input and try again');
		}

		return $response->withRedirect($this->container['router']->pathFor('register.show'));
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

		return $twig->render($response, '/registeremail.twig', ['settings' => $settings]);
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
		$registeredUser = $user->findUsersByEmail($params['confirmationmail']);
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

	# show welcome page after successful registration
	public function showWelcome($request, $response, $args)
	{
		# redirect if logged in
		if(isset($_SESSION['user']))
		{
			return $this->container['response']->withRedirect($this->container['router']->pathFor('user.account'));
		}

		if(isset($_SESSION['tmp_user']))
		{
			unset($_SESSION['tmp_user']);
		}

		if(isset($_SESSION['old']))
		{
			unset($_SESSION['old']);
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

		# dispatch the confirmation for subscriber plugin so invoices are send
		$this->container->dispatcher->dispatch('onUserConfirmed', new OnUserConfirmed($optinuser));

		if(isset($_SESSION['old']))
		{
			unset($_SESSION['old']);
		}

		$this->container['flash']->addMessage('info', 'Your account is confirmed now. Please login.');
		return $response->withRedirect($this->container['router']->pathFor('auth.show'));
	}

	private function sendConfirmationEmail($settings, $userdata, $base_url)
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

	private function sendRegisterNotification($settings, $userdata)
	{
		# do not dispatch twig here because it has been dispatched already
		if(!isset($this->container['mail']))
		{
			$this->container->dispatcher->dispatch('onTwigLoaded');
		}

		# send confirmation mail
		$send = false; 
					
		if(isset($this->container['mail']))
		{
			$username 		= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];
			$emailparts		= explode("@", $userdata['email']);
			$emaildomain	= isset($emailparts[1]) ? $emailparts[1] : 'unknown';

			# create body lines for html and no html mails
			$body 		= "The new user " . $username . " has registered with the domain " . $emaildomain . ". We are waiting for the confirmation now.";

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

	private function sendConfirmationNotification($settings, $username)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		if(!isset($this->container['mail']))
		{
			$this->container->dispatcher->dispatch('onTwigLoaded');
		}

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

					# dispatch the deletion so subscriptions can be deleted
					$this->container->dispatcher->dispatch('onUserDeleted', new OnUserDeleted($user));

				}
			}
		}
	}

	# check if registration mail is in the list of burner mails
	private function isBurnerEmail($email)
	{
		$mailparts = explode("@", $email);

 		if(file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'burnerlist.txt'))
		{
			# read and return the file
			$burnerlist = unserialize(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'burnerlist.txt'));
			if(isset($burnerlist[$mailparts[1]]))
			{
				return true;
			}
		}

		return false;
	}	
}