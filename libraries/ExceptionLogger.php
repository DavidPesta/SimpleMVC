<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

// Customize this class however you like. You can even rewrite this class to insert error logs into a database table.

class ExceptionLogger
{
	public static function log( $ex )
	{
		if( ! is_dir( ERRORS_FOLDER ) ) mkdir( ERRORS_FOLDER, 0777, true );
		
		list( $micro, $seconds ) = explode( " ", microtime() );
		list( $garbage, $micro ) = explode( ".", $micro );
		
		$filename = date( "Y-m-d_H-i-s_", $seconds ) . $micro . ".log";
		
		$error = array();
		$error[ 'code' ] = $ex->getCode();
		$error[ 'message' ] = $ex->getMessage();
		$error[ 'file' ] = str_replace( dirname( $_SERVER[ 'DOCUMENT_ROOT' ] ), "", str_replace( "\\", "/", $ex->getFile() ) );
		$error[ 'line' ] = $ex->getLine();
		$error[ 'REQUEST_TIME' ] = $_SERVER[ 'REQUEST_TIME' ];
		$error[ 'REMOTE_ADDR' ] = $_SERVER[ 'REMOTE_ADDR' ];
		$error[ 'HTTP_REFERER' ] = $_SERVER[ 'HTTP_REFERER' ];
		$error[ 'REQUEST_URI' ] = $_SERVER[ 'REQUEST_URI' ];
		$error[ 'GET' ] = $_GET;
		$error[ 'POST' ] = $_POST;
		$error[ 'SESSION' ] = $_SESSION;
		$error[ 'COOKIE' ] = $_COOKIE;
		$error[ 'trace' ] = $ex->getTrace();
		
		file_put_contents( ERRORS_FOLDER . $filename, var_export( $error, true ) );
		
		return $error;
	}
}
