<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

class Database extends PDO
{
	private $_prefix;
	private $_host;
	private $_port;
	private $_dbname;
	private $_user;
	private $_pass;
	private $_opt;
	
	public function __construct()
	{
		$args = func_get_args();
		
		if( count( $args ) == 0 ) {
			$settings = array();
		}
		else {
			if( is_array( $args[ 0 ] ) ) {
				$prefix = null;
				$settings = $args[ 0 ];
			}
			elseif( is_string( $args[ 0 ] ) && is_array( $args[ 1 ] ) ) {
				$prefix = $args[ 0 ];
				$settings = $args[ 1 ];
			}
			elseif( is_string( $args[ 0 ] ) ) {
				$prefix = $args[ 0 ];
				$settings = array();
			}
			else {
				throw new Exception( "Unexpected parameters for Database constructor: Expects either a prefix string, then a settings array (2 parameters), or just a prefix string (1 parameter), or just a settings array (1 parameter), or no parameters (0 parameters)." );
			}
		}
		
		$settings += array(
			'host'   => 'localhost',
			'port'   => '3306',
			'dbname' => '',
			'user'   => 'root',
			'pass'   => ''
		);
		
		$this->_prefix   = $prefix;
		$this->_host   = $settings[ 'host' ];
		$this->_port   = $settings[ 'port' ];
		$this->_dbname = $settings[ 'dbname' ];
		$this->_user   = $settings[ 'user' ];
		$this->_pass   = $settings[ 'pass' ];
		$this->_opt    = $settings[ 'opt' ];
		
		if( $settings[ 'opt' ] == null ) $settings[ 'opt' ] = array();
		
		$this->invokeParentConstructor();
	}
	
	public function invokeParentConstructor()
	{
		parent::__construct(
			'mysql:host=' . $this->_host . ';port=' . $this->_port . ';dbname=' . $this->_dbname,
			$this->_user,
			$this->_pass,
			$this->_opt
		);
		
		$this->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}
	
	protected static function prepareArgs( $args, & $firstArg, & $remainingArgs )
	{
		$num = count( $args );
		
		if( $firstArg == null ) $firstArg = "";
		if( $remainingArgs == null ) $remainingArgs = array();
		
		for( $i = 0; $i < $num; $i++ ) {
			if( $i == 0 ) $firstArg = $args[ $i ];
			else {
				if( is_array( $args[ $i ] ) ) $remainingArgs = array_merge( $remainingArgs, $args[ $i ] );
				else $remainingArgs[] = $args[ $i ];
			}
		}
	}
	
	public function execute()
	{
		self::prepareArgs( func_get_args(), $sql, $params );
		
		$stmt = $this->prepare( $sql );
		$stmt->execute( $params );
		
		return $stmt;
	}
	
	public function createDatabase( $dbname = "database", $autoConnect = true )
	{
		$stmt = $this->prepare( "create database if not exists $dbname" );
		$stmt->execute();
		
		if( $autoConnect ) $this->useDatabase( $dbname );
	}
	
	public function useDatabase( $dbname )
	{
		$this->_dbname = $dbname;
		$this->invokeParentConstructor();
	}
	
	public function getConnectionSignature()
	{
		return $this->_host . ":" . $this->_port . ":" . $this->_dbname;
	}
	
	public function includeModel( $model )
	{
		if( ! class_exists( $model ) ) {
			include MODEL_FOLDER . $model . ".php";
		}
		
		if( $this->_prefix != null ) {
			eval( "class " . $this->_prefix . "_" . $model . " extends $model {}" );
			$model = $this->_prefix . "_" . $model;
		}
		
		$model::init( $this );
	}
	
	public function autoGenerateRemainingModels()
	{
		if( $this->_dbname == "" ) return;
		
		if( PRODUCTION && APC_EXISTS ) $models = apc_fetch( "dbcache:models:" . $this->getConnectionSignature() );
		else $models = false;
		
		if( $models === false ) {
			$models = array();
			$stmt = $this->prepare( "show tables" );
			$stmt->execute();
			while( $record = $stmt->fetch( PDO::FETCH_NUM ) ) {
				$models[] = implode( array_map( "ucfirst", explode( "_", $record[ 0 ] ) ) );
			}
			if( PRODUCTION && APC_EXISTS ) apc_store( "dbcache:models:" . $this->getConnectionSignature(), $models );
		}
		
		foreach( $models as $model ) {
			if( $this->_prefix != null ) $model = $this->_prefix . "_" . $model;
			if( ! class_exists( $model ) ) {
				eval( "class $model extends FormRecord {}" );
				$model::init( $this );
			}
		}
	}
}
