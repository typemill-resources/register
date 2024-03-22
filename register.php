<?php

namespace Plugins\register;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Typemill\Plugin;
use Typemill\Models\Validation;
use Typemill\Models\User;
use Typemill\Models\StorageWrapper;
use Typemill\Events\OnTwigLoaded;
use Typemill\Events\OnUserConfirmed;
use Typemill\Events\OnUserDeleted;

class Register extends Plugin
{
	protected $settings;

	public static function setPremiumLicence()
	{
		return 'MAKER';
	}

    public static function getSubscribedEvents()
    {
		return array(
			'onPageReady'		=> 'onPageReady'
		);
    }

	public static function addNewRoutes()
	{

		return [
			[
				'httpMethod' 	=> 'get', 
				'route' 		=> '/tm/register', 
				'class' 		=> 'Plugins\Register\Register:showRegistrationForm', 
				'name' 			=> 'register.show'
			],
			[
				'httpMethod' 	=> 'post', 
				'route' 		=> '/tm/register', 
				'class' 		=> 'Plugins\Register\Register:createUser', 
				'name' 			=> 'register.create'
			],
			[
				'httpMethod' 	=> 'get', 
				'route' 		=> '/tm/registerwelcome', 
				'class' 		=> 'Plugins\Register\Register:showWelcome', 
				'name' 			=> 'register.welcome'
			],
			[
				'httpMethod' 	=> 'get', 
				'route' 		=> '/tm/registeroptin', 
				'class' 		=> 'Plugins\Register\Register:optin', 
				'name' 			=> 'register.optin'
			],
			[
				'httpMethod' 	=> 'get', 
				'route' 		=> '/tm/registeremail', 
				'class' 		=> 'Plugins\Register\Register:requestConfirmationEmail', 
				'name' 			=> 'register.requestemail'
			],
			[
				'httpMethod' 	=> 'post', 
				'route' 		=> '/tm/registeremail', 
				'class' 		=> 'Plugins\Register\Register:sendConfirmationEmailAgain', 
				'name' 			=> 'register.sendemail'
			],
		];
	}

	# read last cache time and trigger functions once a day
	public function onPageReady()
	{
		$storage = new StorageWrapper('\Typemill\Models\Storage');
		if($storage->timeoutIsOver('registercheck', 60))
		{
			$this->checkRegisteredUsers($this->urlinfo['baseurl']);
		}

/*
		$now 			= new \DateTime('NOW');

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
*/
	}

	# show the registration form
	public function showRegistrationForm(Request $request, Response $response, $args)
	{
		$settings = $this->getSettings();

		$authenticated = ( 
				(isset($_SESSION['username'])) && 
				(isset($_SESSION['login']))
			)
			? true : false;

		if($authenticated)
		{			
			$router = $this->container->get('routeParser');

			return $response->withHeader('Location', $router->urlFor('user.account'))->withStatus(302);
		}

		$this->addCSS('/register/css/register.css');

		# get the public forms for the plugin
		$registerform = $this->generateForm('register.create');

		$twig   = $this->getTwig();
		$loader = $twig->getLoader();	
		$loader->addPath(__DIR__ . '/templates', 'register');		

	    return $twig->render($response, '@register/registerform.twig', [
			'settings' 			=> $settings, 
			'registerform' 		=> $registerform, 
	    ]);
	}

	# create a new user
	public function createUser(Request $request, Response $response, $args)
	{
		$params = $request->getParsedBody();
		$params = $this->validateParams($params);

		$router = $this->container->get('routeParser');
		$flash 	= $this->container->get('flash');

		if(!$params)
		{
			return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);
		}

		# username, email and password are required, make sure they are there and correctly defined in plugin
		if(
			!isset($params['username']) OR 
			!isset($params['email']) OR 
			!isset($params['password'])
		)
		{
			$flash->addMessage('error', 'The fields username, email and password are required. Maybe the plugin is misconfigured.');
			return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);
		}

		if($this->isBurnerEmail($params['email']))
		{
			return $response->withHeader('Location', $router->urlFor('home'))->withStatus(302);
		}

		$pluginSettings 	= $this->getPluginSettings();
		$base_url			= $this->urlinfo['baseurl'];
		$validate 			= new Validation();
		$user				= new User();

		# check gumroad license
		if(isset($pluginSettings['gumroadpermalink']) && $pluginSettings['gumroadpermalink'] != '')
		{
			if(!isset($params['gumroad']) OR $params['gumroad'] == '')
			{
				$flash->addMessage('error', 'Missing Gumroad License Key');
				return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);
			}

		    if(in_array('curl', get_loaded_extensions()))
		    {
				$gumroad_curl = curl_init();

				curl_setopt($gumroad_curl, CURLOPT_URL,"https://api.gumroad.com/v2/licenses/verify");
				curl_setopt($gumroad_curl, CURLOPT_POST, 1);
				curl_setopt(
					$gumroad_curl, 
					CURLOPT_POSTFIELDS,
					"product_permalink=" 
						. $pluginSettings['gumroadpermalink'] 
						. "&license_key=" . $params['gumroad']
				);

				curl_setopt($gumroad_curl, CURLOPT_RETURNTRANSFER, true);

				$gumroad_curl_result = curl_exec($gumroad_curl);

				curl_close($gumroad_curl);

				$gumroad_result_json = json_decode($gumroad_curl_result);

				if($gumroad_result_json->success != 'true')
				{
					$flash->addMessage('error', 'Incorrect Gumroad License Key');
					return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);			
				}
		    }
		    else
		    {
				# make POST request to gumroad API with php stream

				$postdata = http_build_query(
					array(
						'product_permalink' => $pluginSettings['gumroadpermalink'],
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
					$flash->addMessage('error', 'Incorrect Gumroad License Key');
					return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);			
				}
			}		
		}

		# set member as standard role for user
		$params['userrole'] = 'member';

		# check if another user role has been selected in the plugin configurations
		if(isset($pluginSettings['userrole']) && $pluginSettings['userrole'] != '')
		{
			$params['userrole'] = $pluginSettings['userrole'];
		}

		# get userroles for validation
		$userroles 		= $this->container->get('acl')->getRoles();

		$validation = $validate->newUser($params, $userroles);

		# validate user 
		if($validation !== true)
		{
			$_SESSION['errors'] = $validate->returnFirstValidationErrors($validation);

			$flash->addMessage('error', 'Please check your input and try again');
			
			return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);
		}

		# generate confirmation token 
		$created 		= date("Y-m-d H:i:s");
		$optintoken 	= bin2hex(random_bytes(32));

		$userdata 				= $params;
		$userdata['username']	= '_' . $params['username'];
		$userdata['created']	= $created;
		$userdata['optintoken']	= $optintoken;

		$settings = $this->getSettings();
		
		# create user
		$username = $user->createUser($userdata);

		if(!$username)
		{
			$flash->addMessage('error', 'We could not create the user, please check if settings folder is writable.');

			return $response->withHeader('Location', $router->urlFor('register.show'))->withStatus(302);
		}

		$send = $this->sendConfirmationEmail($pluginSettings, $userdata, $base_url);

		if($send !== true)
		{
			$twig   = $this->getTwig();
			$loader = $twig->getLoader();
			$loader->addPath(__DIR__ . '/templates');

			$this->addCSS('/register/css/register.css');

			return $twig->render($response, '/registererror.twig', [
				'title' 	=> 'Error with Confirmation Mail', 
				'message' 	=> 'Sorry, something went wrong! We created your user account, but we could not send the confirmation mail with the registration link. You cannot login without this confirmation. Please contact the owner of the website and tell him your username so he can solve the problem.'
			]);
		}

		# send mail to admin if feature is activated
		if(isset($pluginSettings['notifyafterregistration']) && $pluginSettings['notifyafterregistration'])
		{
			$send = $this->sendRegisterNotification($pluginSettings, $userdata);
		}

		# show message only on success, otherwise show neutral message

		return $response->withHeader('Location', $router->urlFor('register.welcome'))->withStatus(302);
  	}

	# show page to send confirmation email again
	public function requestConfirmationEmail(Request $request, Response $response, $args)
	{
		$settings = $this->getSettings();

		$authenticated = ( 
				(isset($_SESSION['username'])) && 
				(isset($_SESSION['login']))
			)
			? true : false;

		if($authenticated)
		{			
			$router = $this->container->get('routeParser');

			return $response->withHeader('Location', $router->urlFor('user.account'))->withStatus(302);
		}

		$twig   			= $this->getTwig();  // get the twig-object
		$loader 			= $twig->getLoader();  // get the twig-template-loader	
		$loader->addPath(__DIR__ . '/templates');

		$this->addCSS('/register/css/register.css');

		return $twig->render($response, '/confirmationrequest.twig', [
			'settings' => $settings,
			'base_url' => $this->urlinfo['baseurl']
		]);
	}

	# send the confirmation email again
	public function sendConfirmationEmailAgain(Request $request, Response $response, $args)
	{
		$settings 		= $this->getSettings();
		$router 		= $this->container->get('routeParser');
		$flash 			= $this->container->get('flash');

		$authenticated = ( 
				(isset($_SESSION['username'])) && 
				(isset($_SESSION['login']))
			)
			? true : false;

		if($authenticated)
		{
			return $response->withHeader('Location', $router->urlFor('user.account'))->withStatus(302);
		}

		$params 		= $request->getParsedBody();
		$base_url		= $this->urlinfo['baseurl'];

		# check if input is valid email
		$validate		= new Validation();
		$validator 		= $validate->returnValidator($params);
		$validator->rule('required', ['confirmationmail']);
		$validator->rule('email', 'confirmationmail');
		if(!$validator->validate())
		{
			$flash->addMessage('error', 'Please enter a valid email.');
			return $response->withHeader('Location', $router->urlFor('register.requestemail'))->withStatus(302);
		}

		$user			= new User();

		# this searches over all existing users. You can improve performance with a separate function that only checks users starting with _
		$registeredUser = $user->findUsersByEmail($params['confirmationmail']);
		
		if(!$registeredUser or (count($registeredUser) > 1) )
		{
			$flash->addMessage('error', 'We did not find a user with a valid optin token.');

			return $response->withHeader('Location', $router->urlFor('register.requestemail'))->withStatus(302);
		}

		if(!$user->setUser($registeredUser[0]))
		{
			$flash->addMessage('error', 'We did not find a user with a valid optin token.');

			return $response->withHeader('Location', $router->urlFor('register.requestemail'))->withStatus(302);
		}

		$userdata = $user->getUserData();

		if(!isset($userdata['optintoken']) OR !$userdata['optintoken'])
		{
			$flash->addMessage('error', 'We did not find a user with a valid optin token.');

			return $response->withHeader('Location', $router->urlFor('register.requestemail'))->withStatus(302);
		}

		$send = $this->sendConfirmationEmail($settings['plugins']['register'], $userdata, $base_url);

		if($send !== true)
		{
			$twig   = $this->getTwig();
			$loader = $twig->getLoader();	
			$loader->addPath(__DIR__ . '/templates');

			$this->addCSS('/register/css/register.css');

			return $twig->render($response, '/errorpage.twig', [
				'title' 	=> 'Error With Confirmation Email', 
				'message' 	=> 'Sorry, something went wrong! We created your user account, but we could not send the confirmation mail with the registration link. You cannot login without this confirmation. Please contact the owner of the website and tell him your username so he can solve the problem.'
			]);
		}

		return $response->withHeader('Location', $router->urlFor('register.welcome'))->withStatus(302);
	}

	# show welcome page after successful registration
	public function showWelcome(Request $request, Response $response, $args)
	{
		$authenticated = ( 
				(isset($_SESSION['username'])) && 
				(isset($_SESSION['login']))
			)
			? true : false;

		if($authenticated)
		{			
			$router = $this->container->get('routeParser');

			return $response->withHeader('Location', $router->urlFor('user.account'))->withStatus(302);
		}

		if(isset($_SESSION['old']))
		{
			unset($_SESSION['old']);
		}

		$settings = $this->getSettings();

		$twig   = $this->getTwig();  // get the twig-object
		$loader = $twig->getLoader();  // get the twig-template-loader	
		$loader->addPath(__DIR__ . '/templates');

		$this->addCSS('/register/css/register.css');

		return $twig->render($response, '/confirmationpage.twig', ['settings' => $settings]);
	}

	# show page after user confirmed registration with the optin link
	public function optin(Request $request, Response $response, $args)
	{
		$params 	= $request->getQueryParams();
		$router 	= $this->container->get('routeParser');
		$flash 		= $this->container->get('flash');
		$settings 	= $this->getSettings();

		# redirect if logged in
		$authenticated = ( 
				(isset($_SESSION['username'])) && 
				(isset($_SESSION['login']))
			)
			? true : false;

		if($authenticated)
		{
			return $response->withHeader('Location', $router->urlFor('user.account'))->withStatus(302);
		}

		if(!isset($params['optintoken']) OR !isset($params['username']))
		{
			$flash->addMessage('error', 'We could not confirm your account (missing data). Please try again or contact the administrator.');
			return $response->withHeader('Location', $router->urlFor('auth.show'))->withStatus(302);
		}

		# validate token format
		if( (strlen($params['optintoken']) != 64) OR (!ctype_alnum($params['optintoken'])) )
		{
			$flash->addMessage('error', 'We could not confirm your account (wrong data). Please contact the administrator.');
			return $response->withHeader('Location', $router->urlFor('auth.show'))->withStatus(302);
		}

		$storage = new StorageWrapper($settings['storage']);

		$optinuser = $storage->getYaml('settingsFolder', 'users', '_' . $params['username'] . '.yaml');

		if(!$optinuser)
		{
			$flash->addMessage('error', 'We did not find that user. Please try again or contact the administrator.');
			return $response->withHeader('Location', $router->urlFor('auth.show'))->withStatus(302);
		}

		if($optinuser['optintoken'] != $params['optintoken'])
		{
			$flash->addMessage('error', 'We could not confirm your account (wrong token). Please try again or contact the administrator.');
			return $response->withHeader('Location', $router->urlFor('auth.show'))->withStatus(302);
		}

		$optinuser['username'] 		= ($optinuser['username'][0] == '_') ? ltrim($optinuser['username'], '_') : $optinuser['username'];
		$optinuser['optintoken'] 	= false;

		$storage->updateYaml('settingsFolder', 'users', '_' . $optinuser['username'] . '.yaml', $optinuser);
		$storage->renameFile('settingsFolder', 'users', '_' . $optinuser['username'] . '.yaml', $optinuser['username'] . '.yaml');

		# send confirmation notification to admin, if activated
		if(isset($settings['plugins']['register']['notifyafterconfirmation']) && $settings['plugins']['register']['notifyafterconfirmation'])
		{
			$send = $this->sendConfirmationNotification($settings['plugins']['register'], $optinuser);
		}

		# dispatch the confirmation for subscriber plugin so invoices are send
		$dispatcher = $this->container->get('dispatcher');
		$dispatcher->dispatch(new OnUserConfirmed($optinuser), 'onUserConfirmed');

		if(isset($_SESSION['old']))
		{
			unset($_SESSION['old']);
		}

		$flash->addMessage('info', 'Your account is confirmed now. Please login.');
		return $response->withHeader('Location', $router->urlFor('auth.show'))->withStatus(302);
	}

	private function sendConfirmationEmail($pluginSettings, $userdata, $base_url)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		$dispatcher = $this->container->get('dispatcher');
		$dispatcher->dispatch(new OnTwigLoaded(false), 'onTwigLoaded');

		$send = false; 
					
		if($this->container->has('mail'))
		{
		    $mail 		= $this->container->get('mail');

			$username 	= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];

			# create body lines for html and no html mails
			$body1 		= $pluginSettings['mailsalutation'] . " " . $username . ",";
			$body2 		= "\n\n" . $pluginSettings['mailbeforelink'];
			$body3 		= "\n\n" . $base_url . "/tm/registeroptin?optintoken=" . $userdata['optintoken'] . "&username=" . $username;
			$body3html 	= "\n\n" . "[Registration Link](" . $base_url . "/tm/registeroptin?optintoken=" . $userdata['optintoken'] . "&username=" . $username . ")";
			$body4 		= "";
			if(isset($pluginSettings['mailafterlink']) && $pluginSettings['mailafterlink'] && $pluginSettings['mailafterlink'] != '')
			{
				$body4 		= "\n\n" . $pluginSettings['mailafterlink'];
			}

			# body without html
			$body 		= $body1 . $body2 . $body3 . $body4;
					
			# body with html
			$bodyhtml 	= $body1 . $body2 . $body3html . $body4;
			$bodyhtml 	= $this->markdownToHtml($bodyhtml);

			$mail->ClearAllRecipients();
			$mail->addAdress($userdata['email']);
			$mail->addReplyTo($pluginSettings['mailreplyto'], $pluginSettings['mailreplytoname']);
			$mail->setSubject($pluginSettings['mailsubject']);
			$mail->setBody($bodyhtml);
			$mail->setAltBody($body);

			$send = $mail->send();
		}
	
		return $send;
	}

	private function sendRegisterNotification($pluginSettings, $userdata)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		$dispatcher = $this->container->get('dispatcher');
		$dispatcher->dispatch(new OnTwigLoaded(false), 'onTwigLoaded');

		$send = false; 

		if($this->container->has('mail'))
		{
			$mail 			= $this->container->get('mail');
			$username 		= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];
			$emailparts		= explode("@", $userdata['email']);
			$emaildomain	= isset($emailparts[1]) ? $emailparts[1] : 'unknown';

			# create body lines for html and no html mails
			$body 		= "The new user " . $username . " has registered with the domain " . $emaildomain . ". We are waiting for the confirmation now.";

			$mail->ClearAllRecipients();
			$mail->addAdress($pluginSettings['mailreplyto']);
			$mail->setSubject("New user: " . $username);
			$mail->setBody($body);
			$mail->setAltBody($body);

			$send = $mail->send();
		}
	
		return $send;
	}

	private function sendConfirmationNotification($pluginSettings, $userdata)
	{
		# we have to dispatch onTwigLoaded to get the mail-function from the mail-plugin into the container
		$dispatcher = $this->container->get('dispatcher');
		$dispatcher->dispatch(new OnTwigLoaded(false), 'onTwigLoaded');

		$send = false; 
					
		if($this->container->has('mail'))
		{
			$mail 			= $this->container->get('mail');
			$username 		= ($userdata['username'][0] == '_') ? ltrim($userdata['username'], '_') : $userdata['username'];
			$emailparts		= explode("@", $userdata['email']);
			$emaildomain	= isset($emailparts[1]) ? $emailparts[1] : 'unknown';

			# create body lines for html and no html mails
			$body 			= "The new user " . $username . " has confirmed his account with the domain " . $emaildomain . ".";

			$mail->ClearAllRecipients();
			$mail->addAdress($pluginSettings['mailreplyto']);
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
		$pluginSettings = $this->getPluginSettings();

		# get interval for reminder
		$remind = ( isset($pluginSettings['reminduser']) ) ? $pluginSettings['reminduser'] : 5;
		$remind = 'P'.$remind.'D';

		# get interval for delete
		$delete = ( isset($pluginSettings['deleteuser']) ) ? $pluginSettings['deleteuser'] : 5;
		$delete = 'P'.$delete.'D';
		
		$userModel = new User();
		$usernames = $userModel->getAllUsers();

		foreach($usernames as $key => $username)
		{
			if($username[0] == '_')
			{
				if(!$userModel->setUserWithPassword($username))
				{
					continue;
				}

				$userdata 		= $userModel->getUserData();

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
					$send = $this->sendConfirmationEmail($pluginSettings, $userdata, $baseUrl);

					$userModel->setValue('optinreminder', $now->format('Y-m-d H:i:s'));

					if($send !== true)
					{
						$userModel->setValue('optinreminder', $now->format('Y-m-d H:i:s') . ' Could not send email.');
					}

					# update the user with the reminder date
					$userModel->updateUser();
				}

				if($deleteuser <= $nowFormat)
				{
					$userModel->deleteUser();

					# dispatch the deletion so subscriptions can be deleted
					$this->container->get('dispatcher')->dispatch(new OnUserDeleted($userdata), 'onUserDeleted');
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