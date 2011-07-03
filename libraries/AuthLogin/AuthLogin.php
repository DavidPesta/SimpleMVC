<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

class AuthLogin
{
	private static $_loginRedirect = "/home"; // The URL to redirect to after the user successfully logs in
	private static $_logoutRedirect = "/login"; // The URL to redirect to after the user logs out
	private static $_forgotPasswordLink = "/forgot-password"; // The URL location of the Forgot Password page
	private static $_rememberMeDays = 30; // The number of days that the Remember Me feature remembers the user
	private static $_createAccountMode = "emailActivation"; // The default mode for create account functionality
	
	public static function init( $settings )
	{
		self::$_loginRedirect = $settings[ 'loginRedirect' ] ?: self::$_loginRedirect;
		self::$_logoutRedirect = $settings[ 'logoutRedirect' ] ?: self::$_logoutRedirect;
		self::$_forgotPasswordLink = $settings[ 'forgotPasswordLink' ] ?: self::$_forgotPasswordLink;
		self::$_rememberMeDays = $settings[ 'rememberMeDays' ] ?: self::$_rememberMeDays;
		self::$_createAccountMode = $settings[ 'createAccountMode' ] ?: self::$_createAccountMode;
	}
	
	// TODO: If activatedAt is null, then re-send the activation email and let them know to check their email and click on the link to activate their account
	// TODO: If banned until is greater then current time, then let them know that they are banned for # days, # hours, # minutes (create class for this)
	// This method should be invoked inside of a controller such as controllers/login.php
	public static function login()
	{
		$view =& SimpleMVC::$_view;
		
		// Default location of view file for this method
		SimpleMVC::$_contentFolder = LIBRARY_FOLDER . "AuthLogin" . "/";
		SimpleMVC::$_contentFile = "login";
		
		$view->prepopulate = array();
		$view->messages = array();
		$view->emailRemembered = null;
		
		$view->forgotPasswordLink = self::$_forgotPasswordLink;
		
		$view->prepopulate[ 'email' ] = $_GET[ 'email' ];
		
		if( ! empty( $_POST ) ) {
			$email = trim( $_POST[ 'email' ] );
			
			$view->prepopulate[ 'email' ] = $email;
			
			if( isset( $_COOKIE[ 'rememberMe' ] ) && $email != null ) {
				$user = Users::fetchRecord( "userId = ?", $_COOKIE[ 'rememberMe' ] );
				if( $user->email == $email ) {
					$_SESSION[ 'userId' ] = $user->userId;
					setcookie( "rememberMe", $user->userId, time() + 60 * 60 * 24 * self::$_rememberMeDays ); // Set Remember Me again every time they log in with it
					
					if( headers_sent() ) echo '<meta http-equiv="REFRESH" content="0;url=' . self::$_loginRedirect . '">';
					else header( "Location: " . self::$_loginRedirect );
					exit;
				}
				else {
					unset( $_COOKIE[ 'rememberMe' ] );
					setcookie( "rememberMe", "", 1 );
				}
			}
			
			if( $email == null ) $view->messages[] = "You must enter an email address to login.";
			if( $_POST[ 'password' ] == null ) $view->messages[] = "You must enter your password to login.";
			
			if( $email != null && $_POST[ 'password' ] != null ) {
				$user = Users::fetchRecord( "email = ?", $email );
				
				if( $user == null ) $view->messages[] = "The email login '" . $email . "' could not be found in the system.";
				elseif( $user->password != $_POST[ 'password' ] ) {
					$view->messages[] = "The password you entered was incorrect. <a id='forgotPasswordLink-response' href='" . $view->forgotPasswordLink . "/email/" . str_replace( '"', '', $email ) . "'>Forgot password?</a>";
				}
				else {
					$_SESSION[ 'userId' ] = $user->userId;
					if( $_POST[ 'rememberMe' ] == 1 ) setcookie( "rememberMe", $user->userId, time() + 60 * 60 * 24 * self::$_rememberMeDays );
					else setcookie( "rememberMe", "", 1 );
					
					if( headers_sent() ) echo '<meta http-equiv="REFRESH" content="0;url=' . self::$_loginRedirect . '">';
					else header( "Location: " . self::$_loginRedirect );
					exit;
				}
			}
		}
		else {
			if( isset( $_COOKIE[ 'rememberMe' ] ) ) {
				$user = Users::fetchRecord( "userId = ?", $_COOKIE[ 'rememberMe' ] );
				$view->emailRemembered = $user->email;
			}
		}
		
		// TODO: System that lets them change their password
		
		// TODO: Encrypt and decrypt email address using the common encryption key found in the bootstrap, and store the initialization vector along with the hash separated by a colon--wait, isn't email used to login? We cannot encrypt email if we use it as a login.
		//       Find the length upper limit for the email address and impose this limit in validation
	}
	
	// This method should be invoked inside of a controller such as controllers/logout.php
	public static function logout()
	{
		session_destroy();
		
		if( headers_sent() ) echo '<meta http-equiv="REFRESH" content="0;url=' . self::$_logoutRedirect . '">';
		else header( "Location: " . self::$_logoutRedirect );
		exit;
	}
	
	// This method should be invoked inside of a controller such as controllers/forgot-password.php
	public static function forgotPassword()
	{
		$view =& SimpleMVC::$_view;
		
		// Default location of view file for this method
		SimpleMVC::$_contentFolder = LIBRARY_FOLDER . "AuthLogin" . "/";
		SimpleMVC::$_contentFile = "forgot-password";
		
		if( ! empty( $_POST ) ) {
			$email = trim( $_POST[ 'email' ] );
			
			$user = Users::fetchRecord( "email = ?", $email );
			if( $user == null ) {
				$view->messages[] = $email . " could not be found in the system. Please double-check and try again.";
			}
			else {
				//TODO: Create an email class and use it to email password to person with settings in bootstrap, and utilize database email template
				//      Use gmail account as a proxy through which to send email, just for testing, but make the class flexible to receive whatever info needed
				//echo "password is: " . $user->password;
				//if problem $view->messages[] = "There was a problem sending email to your email address. Please try again.";
				$view->success = true;
			}
		}
		else {
			$email = trim( $_GET[ 'email' ] );
		}
		
		$view->email = $email;
		
		// TODO: implement the rest of the email template class and use it for forgot-password and createAccount
	}
	
	// TODO: System that lets the user create an account and performs email validation with a few different options:
	//       generate password and send it to them as method of email validation
	//       let them choose their own password and send them an activation link in email--can resend the activation link as many times as they like
	//       auto-activate without email validation
	// This method should be invoked inside of a controller such as controllers/create-account.php
	public static function createAccount()
	{
		$view =& SimpleMVC::$_view;
		
		// Default location of view file for this method
		SimpleMVC::$_contentFolder = LIBRARY_FOLDER . "AuthLogin" . "/";
		SimpleMVC::$_contentFile = "create-account";
		
		$view->createAccountMode = self::$_createAccountMode;
		
		// Let the user input their email login and choose their own password, then email them an activation link to validate their email login
		if( self::$_createAccountMode == "emailActivation" ) {
			if( ! empty( $_POST ) ) {
				$user = Users::createRecord( $_POST );
				if( $user->isValid() == true ) {
					echo "email activation link to user";
					exit;
				}
			}
			else {
				$user = Users::createRecord();
			}
		}
		
		// Let the user input their email login and choose their own password and auto-activate them without validating their email login
		if( self::$_createAccountMode == "autoActivate" ) {
			if( ! empty( $_POST ) ) {
				$user = Users::createRecord( $_POST );
			}
			else {
				$user = Users::createRecord();
			}
		}
		
		// Let the user input their email login only, then the system will email them a randomly generated password that they can change later
		if( self::$_createAccountMode == "emailPassword" ) {
			// TODO: For auto-generating the password, use a default character set and be able to pass in a custom character set in the bootstrap
			// The email that is sent will give them a link that pre-populates the username and password fields
		}
		
		if( $user != null ) {
			$user->changeSchemaRule( "password", "element", "password" );
			$view->user = $user;
		}
	}
}
