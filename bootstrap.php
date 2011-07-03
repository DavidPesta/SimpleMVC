<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

/*
* This file defines constants, initializes settings and loads all of the resources
* that your router and controllers will need. If you want all of your controllers
* to load some resource or class, this file is a great place to include it.
*/


// Define constants

define( "PRODUCTION", false );
define( "SEO_FRIENDLY_LINKS", true );

define( "APC_EXISTS", extension_loaded('apc') );

define( "ROOT_FOLDER", dirname( __FILE__ ) . "/" );
define( "LIBRARY_FOLDER", ROOT_FOLDER . "libraries" . "/" );
define( "MODEL_FOLDER", ROOT_FOLDER . "models" . "/" );
define( "VIEW_FOLDER", ROOT_FOLDER . "views" . "/" );
define( "LAYOUT_FOLDER", VIEW_FOLDER . "layouts" . "/" );
define( "CONTROLLER_FOLDER", ROOT_FOLDER . "controllers" . "/" );
define( "WEBROOT_FOLDER", ROOT_FOLDER . "webroot" . "/" );
define( "ERRORS_FOLDER", ROOT_FOLDER . "errors" . "/" );


// Error Reporting

error_reporting( E_ALL & ~ ( E_STRICT | E_NOTICE ) );
if( PRODUCTION == true ) {
	ini_set( "display_errors", 0 );
	ini_set( "display_startup_errors", 0 );
}
else {
	ini_set( "display_errors", 1 );
	ini_set( "display_startup_errors", 1 );
}


// Miscellaneous initializations

date_default_timezone_set( "America/Chicago" );
ini_set( 'magic_quotes_runtime', 'off' );
if( get_magic_quotes_gpc() ) include LIBRARY_FOLDER . "disableMagicQuotes.php";
session_start();


// Add the resources you wish to use for your controllers

include LIBRARY_FOLDER . "SimpleMVC.php";
include LIBRARY_FOLDER . "Database.php";
include LIBRARY_FOLDER . "ExceptionLogger.php";
include LIBRARY_FOLDER . "FormRecord/FormRecord.php";
include LIBRARY_FOLDER . "DatabaseCache.php";
include LIBRARY_FOLDER . "AuthLogin/AuthLogin.php";


// Create database handlers and initialize models

$dbh = new Database(array(
	'host'   => 'localhost',
	'port'   => '3306',
	'dbname' => 'simplemvc',
	'user'   => 'root',
	'pass'   => ''
));

$dbh->autoGenerateRemainingModels();


// Initialize AuthLogin variables

AuthLogin::init(array(
));
