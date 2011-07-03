<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

include "../bootstrap.php";

try {
	if( SEO_FRIENDLY_LINKS ) {
		$normalizedParams = str_replace( array( "?", "=", "&" ), "/", $_SERVER[ 'REQUEST_URI' ] );
		$normalizedScript = str_replace( ".php", "", $normalizedParams );
		$uriTokens = explode( "/", trim( $normalizedScript, "/" ) );
		
		$controllerPath = "";
		$controller = "";
		$viewFile = "";
		
		do {
			$token = array_shift( $uriTokens );
			if( is_file( CONTROLLER_FOLDER . $controllerPath . "index.php" ) ) $controller = $controllerPath . "index";
			elseif( is_file( VIEW_FOLDER . $controllerPath . "index.phtml" ) ) $viewFile = $controllerPath . "index";
			if( is_file( CONTROLLER_FOLDER . $controllerPath . $token . ".php" ) ) $controller = $controllerPath . $token;
			elseif( is_file( VIEW_FOLDER . $controllerPath . $token . ".phtml" ) ) {
				if( basename( $controller ) == "index" ) $controller = "";
				$viewFile = $controllerPath . $token;
			}
			if( is_dir( CONTROLLER_FOLDER . $controllerPath . $token ) ) $controllerPath .= $token . "/";
			elseif( is_dir( VIEW_FOLDER . $controllerPath . $token ) ) $controllerPath .= $token . "/";
			else break;
		} while( $token != null );
		
		if( $controller == "" && $viewFile == "" ) {
			throw new Exception( "Could not find file, controller, or view!" );
		}
		else {
			$paramString = str_replace( "/" . str_replace( "/index", "", $controller ), "", $normalizedScript );
			$paramTokens = explode( "/", ltrim( $paramString, "/" ) );
			$numTokens = count( $paramTokens );
			for( $i = 0; $i < $numTokens; $i += 2 ) {
				while( $paramTokens[ $i ] == null && $i < $numTokens ) $i++;
				if( $paramTokens[ $i ] != null ) $_GET[ $paramTokens[ $i ] ] = $paramTokens[ $i + 1 ];
				if( $paramTokens[ $i ] != null ) $_REQUEST[ $paramTokens[ $i ] ] = $paramTokens[ $i + 1 ];
			}
			SimpleMVC::$_layoutFile = "default";
			SimpleMVC::$_contentFile = $controller;
			define( "CONTROLLER", $controller );
			if( $controller != "" ) {
				$controllerPath = dirname( CONTROLLER_FOLDER . $controller );
				if( is_file( $controllerPath . "/predispatch.php" ) ) include $controllerPath . "/predispatch.php";
				include CONTROLLER_FOLDER . $controller . ".php";
				if( is_file( $controllerPath . "/postdispatch.php" ) ) include $controllerPath . "/postdispatch.php";
			}
			elseif( $viewFile != "" ) {
				$controllerPath = dirname( CONTROLLER_FOLDER . $viewFile );
				if( is_file( $controllerPath . "/predispatch.php" ) ) include $controllerPath . "/predispatch.php";
				if( is_file( $controllerPath . "/postdispatch.php" ) ) include $controllerPath . "/postdispatch.php";
				SimpleMVC::$_contentFile = $viewFile;
			}
			SimpleMVC::showView();
		}
	}
	else {
		$controller = "";
		$viewFile = "";
		
		$uriVars = parse_url( $_SERVER[ 'REQUEST_URI' ] );
		$path = trim( $uriVars[ 'path' ], "/" );
		
		if( is_file( CONTROLLER_FOLDER . $path ) ) $controller = $path;
		elseif( is_file( CONTROLLER_FOLDER . $path . "/index.php" ) ) $controller = $path . "/index.php";
		elseif( is_file( VIEW_FOLDER . $path ) ) $viewFile = $path;
		elseif( is_file( VIEW_FOLDER . $path . "/index.phtml" ) ) $viewFile = $path . "/index.phtml";
		
		if( $controller == "" && $viewFile == "" ) {
			throw new Exception( "Could not find file, controller, or view!" );
		}
		else {
			// $_GET and $_REQUEST are automatically populated
			SimpleMVC::$_layoutFile = "default";
			SimpleMVC::$_contentFile = dirname( $controller ) . "/" . basename( $controller, strrchr( $controller, "." ) );
			define( "CONTROLLER", $controller );
			if( CONTROLLER != "" ) {
				$controllerPath = dirname( CONTROLLER_FOLDER . $controller );
				if( is_file( $controllerPath . "/predispatch.php" ) ) include $controllerPath . "/predispatch.php";
				include CONTROLLER_FOLDER . $controller;
				if( is_file( $controllerPath . "/postdispatch.php" ) ) include $controllerPath . "/postdispatch.php";
			}
			elseif( $viewFile != "" ) {
				$controllerPath = dirname( CONTROLLER_FOLDER . $viewFile );
				if( is_file( $controllerPath . "/predispatch.php" ) ) include $controllerPath . "/predispatch.php";
				if( is_file( $controllerPath . "/postdispatch.php" ) ) include $controllerPath . "/postdispatch.php";
				SimpleMVC::$_contentFile = dirname( $viewFile ) . "/" . basename( $viewFile, strrchr( $viewFile, "." ) );
			}
			SimpleMVC::showView();
		}
	}
}
catch( exception $ex ) {
	$error = ExceptionLogger::log( $ex );
	
	SimpleMVC::$_layoutFile = "default";
	SimpleMVC::$_contentFile = "error";
	
	if( PRODUCTION == false ) $view->error = $error;
	
	SimpleMVC::showView();
}
