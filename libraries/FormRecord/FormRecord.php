<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

class FormRecord
{
	// Properties which each child class must have its own copy
	protected static $_dbh;
	protected static $_table; // By default, the table name is derived from the name of the child class. Override this in child definition to set it explicitly.
	protected static $_schemaRules;
	protected static $_primaryKeys;
	
	// Properties that can be overridden in the child class definition
	protected static $_prettyNames = array(); // Indexed by field name. The value becomes the name for this field in display contexts.
	protected static $_customFieldMessages = array(); // Indexed by field name. The value is the alternative validation message for this field.
	protected static $_overrideSchemaRules = array(); // Indexed by field name and follows same format as $_schemaRules to override its values.
	
	protected $_originalData = array(); // Data in this array represents original data as it exists in the database
	protected $_currentData = array(); // Data in this array represents new data that may be out of sync with the database
	
	public $_valid = null; // This is set to false in isValid() method when form elements for this object are not valid--can be set directly if manually validating
	
	public static function init( & $dbh )
	{
		/************************************************* We start this beautiful class with a hack! *************************************************************
		* The code that follows allows a child class to inherit its own copies of parent static properties without needing to place them in its class definition.
		* An unusual condition exists in PHP that requires a simple workaround to get this to work. The following URLs explain what is happening here.
		* - bugs.php.net/49105 (Static properties aren't given their own place in memory for children that extend their parent. PHP bug? Maybe, maybe not.)
		* - stackoverflow.com/questions/4577187/php-5-3-late-static-binding-doesnt-work-for-properties-when-defined-in-parent-c (Re-assign the property's pointer.)
		* Future Note - If static:: starts to throw an error in a future version of PHP, this file can be changed to invoke $calledClass:: in place of static::
		**********************************************************************************************************************************************************/
		
		$calledClass = get_called_class();
		
		$calledClass::$_dbh =& $dbh;
		
		// If the called class happens to be the parent class, don't process any further because there is no database table associated with the parent class
		if( get_parent_class( $calledClass ) == false ) return;
		
		// By default, table name is derived from child class name. This can be overridden by defining the property in the child class with an explicit value.
		if( static::$_table == null ) {
			$table = $calledClass;
			if( strpos( $table, "_" ) !== false ) list( $trash, $table ) = explode( "_", $table ); // Remove the prefix used for multiple databases
			$table = strtolower( preg_replace( "/([^A-Z])([A-Z])/", "$1_$2", $table ) );
			$calledClass::$_table =& $table;
		}
		
		$schemaRules = null;
		$calledClass::$_schemaRules =& $schemaRules;
		
		$primaryKeys = null;
		$calledClass::$_primaryKeys =& $primaryKeys;
		
		// Now we load the schema rules from APC cache if we're in production mode
		if( PRODUCTION && APC_EXISTS ) {
			static::$_schemaRules = apc_fetch( "dbcache:schemaRules:" . static::$_dbh->getConnectionSignature() . ":" . $calledClass );
			static::$_primaryKeys = apc_fetch( "dbcache:primaryKeys:" . static::$_dbh->getConnectionSignature() . ":" . $calledClass );
		}
		
		// If schemaRules is empty at this point, then generate them
		if( ! is_array( static::$_schemaRules ) || ! is_array( static::$_primaryKeys ) ) {
			self::autoCreateSchemaRules();
			if( PRODUCTION && APC_EXISTS ) {
				apc_store( "dbcache:schemaRules:" . static::$_dbh->getConnectionSignature() . ":" . $calledClass, static::$_schemaRules );
				apc_store( "dbcache:primaryKeys:" . static::$_dbh->getConnectionSignature() . ":" . $calledClass, static::$_primaryKeys );
			}
		}
	}
	
	public function __construct( $data = array() )
	{
		$this->setData( $data );
	}
	
	public function setData( $data, $trim = true )
	{
		if( $data instanceof self ) $data = $data->getData();
		foreach( $data as $field => $value ) {
			if( $trim == true ) $value = trim( $value );
			$this->_currentData[ $field ] = self::formatValueForObject( static::$_schemaRules[ $field ], $value );
		}
	}
	
	// This function should only be used by fetchRecord() and fetchRecords() when original data is loaded from the database, or save() after an insert or update
	public function copyCurrentToOriginal()
	{
		$this->_originalData = array(); // Reset _originalData here to account for overriding _originalData values with null values from _currentData which are empty
		foreach( $this->_currentData as $field => $value ) {
			$this->_originalData[ $field ] = $value;
		}
	}
	
	public function getData()
	{
		return $this->_currentData;
	}
	
	public function __set( $field, $value )
	{
		$this->_currentData[ $field ] = self::formatValueForObject( static::$_schemaRules[ $field ], $value );
	}
	
	public function __get( $field )
	{
		return $this->_currentData[ $field ];
	}
	
	public static function autoCreateSchemaRules()
	{
		$stmt = static::$_dbh->prepare( "DESCRIBE " . static::$_table );
		$stmt->execute();
		$tableSchema = $stmt->fetchAll( PDO::FETCH_ASSOC );
		
		static::$_schemaRules = array();
		static::$_primaryKeys = array();
		
		foreach( $tableSchema as $fieldSchema ) {
			static::$_schemaRules[ $fieldSchema[ 'Field' ] ] = self::createFieldRules( $fieldSchema );
		}
		
		foreach( static::$_overrideSchemaRules as $field => $rules ) {
			foreach( $rules as $rule => $value ) {
				if( $rule == "options" ) continue; // Prevent conflict as options are handled below where $rule == "type"
				
				if( $value === null ) unset( static::$_schemaRules[ $field ][ $rule ] );
				else static::$_schemaRules[ $field ][ $rule ] = $value;
				
				if( $rule == "primary_key" && ( $value === null || $value == 0 ) ) {
					$removeKey = array_search( $field, static::$_primaryKeys );
					unset( static::$_primaryKeys[ $removeKey ] );
					unset( static::$_schemaRules[ $field ][ 'auto_increment' ] );
				}
				
				if( $rule == "primary_key" && $value == 1 ) {
					static::$_primaryKeys[] = $field;
					static::$_primaryKeys = array_unique( static::$_primaryKeys );
				}
				
				if( $rule == "type" && ( $value == "singleChoice" || $value == "multiChoice" ) ) {
					$options = $rules[ 'options' ];
					
					$refTable = null;
					$idColumn = null;
					$labelColumn = null;
					
					if( is_string( $options ) ) {
						$refTable = $options;
					}
					elseif( is_array( $options ) && is_string( $options[ 'refTable' ] ) ) {
						$refTable = $options[ 'refTable' ];
						$idColumn = $options[ 'idColumn' ];
						$labelColumn = $options[ 'labelColumn' ];
					}
					else {
						static::$_schemaRules[ $field ][ 'options' ] = $options;
						continue;
					}
					
					$idColumn = $idColumn ?: self::findIdColumn( $refTable );
					$labelColumn = $labelColumn ?: self::findLabelColumn( $refTable );
					
					static::$_schemaRules[ $field ][ 'options' ] = self::fetchFieldOptions( $refTable, $idColumn, $labelColumn );
					
					if( is_array( $options ) ) {
						foreach( $options as $id => $label ) {
							if( $id !== "refTable" && $id !== "idColumn" && $id !== "labelColumn" ) {
								if( $label === null ) unset( static::$_schemaRules[ $field ][ 'options' ][ $id ] );
								else static::$_schemaRules[ $field ][ 'options' ][ $id ] = $label;
							}
						}
						
						// Making the null key element ordered at the beginning of the array for every situation is difficult and needs the following craziness
						ksort( static::$_schemaRules[ $field ][ 'options' ] );
						$tempArray = array();
						$nullValue = null;
						foreach( static::$_schemaRules[ $field ][ 'options' ] as $key => $value ) {
							if( $key === null ) {
								$nullValue = $value;
								continue;
							}
							$tempArray[ $key ] = $value;
						}
						static::$_schemaRules[ $field ][ 'options' ] = array( null => $nullValue );
						foreach( $tempArray as $key => $value ) static::$_schemaRules[ $field ][ 'options' ][ $key ] = $value;
					}
					
					if( ! isset( $rules[ 'element' ] ) ) static::$_schemaRules[ $field ][ 'element' ] = "select";
				}
			}
		}
	}
	
	public static function createFieldRules( $fieldSchema )
	{
		//echo "<pre><font color='blue'>".print_r($fieldSchema, 1)."</font></pre>";
		
		$fieldRule = array();
		
		$rawType = $fieldSchema[ 'Type' ];
		
		preg_match('#\((.*?)\)#', $fieldSchema[ 'Type' ], $match); // match inside the parenthesis
		$parenthesis = $match[ 1 ];
		
		if( strpos( $fieldSchema[ 'Type' ], "unsigned" ) !== false ) $unsigned = true;
		else $unsigned = false;
		
		list( $type ) = preg_split( "/[( ]/", $rawType );
		
		$fieldRule[ 'name' ] = $fieldSchema[ 'Field' ];
		$fieldRule[ 'prettyName' ] = static::$_prettyNames[ $fieldSchema[ 'Field' ] ] ?: self::createPrettyName( $fieldSchema[ 'Field' ] );
		
		if( $fieldSchema[ 'Null' ] == 'NO' ) $fieldRule[ 'required' ] = 1;
		else $fieldRule[ 'required' ] = 0;
		
		switch( $type ) {
			
			case 'tinyint':
				$fieldRule[ 'type' ] = "integer";
				if( $parenthesis == 1 ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 1;
				}
				else {
					if( $unsigned ) {
						$fieldRule[ 'minValue' ] = 0;
						$fieldRule[ 'maxValue' ] = 255;
					}
					else {
						$fieldRule[ 'minValue' ] = -128;
						$fieldRule[ 'maxValue' ] = 127;
					}
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'smallint':
				$fieldRule[ 'type' ] = "integer";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 65535;
				}
				else {
					$fieldRule[ 'minValue' ] = -32768;
					$fieldRule[ 'maxValue' ] = 32767;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'mediumint':
				$fieldRule[ 'type' ] = "integer";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 16777215;
				}
				else {
					$fieldRule[ 'minValue' ] = -8388608;
					$fieldRule[ 'maxValue' ] = 8388607;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'int':
				$fieldRule[ 'type' ] = "integer";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 4294967295;
				}
				else {
					$fieldRule[ 'minValue' ] = -2147483648;
					$fieldRule[ 'maxValue' ] = 2147483647;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'bigint':
				$fieldRule[ 'type' ] = "integer";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 18446744073709551615;
				}
				else {
					$fieldRule[ 'minValue' ] = -9223372036854775808;
					$fieldRule[ 'maxValue' ] = 9223372036854775807;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'float':
				$fieldRule[ 'type' ] = "decimal";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 3.402823466E+38;
				}
				else {
					$fieldRule[ 'minValue' ] = -3.402823466E+38;
					$fieldRule[ 'maxValue' ] = 3.402823466E+38;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'double':
				$fieldRule[ 'type' ] = "decimal";
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 1.7976931348623157E+308;
				}
				else {
					$fieldRule[ 'minValue' ] = -1.7976931348623157E+308;
					$fieldRule[ 'maxValue' ] = 1.7976931348623157E+308;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			// Decimal can deal with values much larger than what PHP can deal with using any of its numeric formats, so it will be dealt with using bcmath
			case 'decimal':
				list( $M, $D ) = explode( ",", $parenthesis . ",0" ); // ",0" added because if $D doesn't exist, it defaults to 0 according to MySQL Documentation
				$maxValue = str_repeat( "9", $M );
				$fieldRule[ 'type' ] = "bcmath"; // use 'string' type instead?
				if( $D > 0 ) $maxValue = substr_replace( $maxValue, ".", $M - $D, 0 );
				if( $unsigned ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = $maxValue;
				}
				else {
					$fieldRule[ 'minValue' ] = "-$maxValue";
					$fieldRule[ 'maxValue' ] = $maxValue;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'char':
			case 'varchar':
				$fieldRule[ 'type' ] = "string";
				$fieldRule[ 'maxLength' ] = $parenthesis;
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'tinytext':
				$fieldRule[ 'type' ] = "string";
				$fieldRule[ 'maxLength' ] = 255;
				$fieldRule[ 'element' ] = "textarea";
				break;
			
			case 'text':
				$fieldRule[ 'type' ] = "string";
				$fieldRule[ 'maxLength' ] = 65535;
				$fieldRule[ 'element' ] = "textarea";
				break;
			
			case 'mediumtext':
				$fieldRule[ 'type' ] = "string";
				$fieldRule[ 'maxLength' ] = 16777215;
				$fieldRule[ 'element' ] = "textarea";
				break;
			
			case 'longtext':
				$fieldRule[ 'type' ] = "string";
				$fieldRule[ 'maxLength' ] = 4294967295;
				$fieldRule[ 'element' ] = "textarea";
				break;
			
			case 'datetime':
				$fieldRule[ 'type' ] = "datetime";
				$fieldRule[ 'minValue' ] = -2147483648;
				$fieldRule[ 'maxValue' ] = 2147483647;
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'date':
				$fieldRule[ 'type' ] = "date";
				$fieldRule[ 'minValue' ] = -2147483648;
				$fieldRule[ 'maxValue' ] = 2147483647;
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'time':
				$fieldRule[ 'type' ] = "time";
				$fieldRule[ 'minValue' ] = -2147483648;
				$fieldRule[ 'maxValue' ] = 2147483647;
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'timestamp':
				$fieldRule[ 'type' ] = "timestamp";
				$fieldRule[ 'minValue' ] = 21601;
				$fieldRule[ 'maxValue' ] = 2147483647;
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'year':
				$fieldRule[ 'type' ] = "integer";
				if( $parenthesis == 2 ) {
					$fieldRule[ 'minValue' ] = 0;
					$fieldRule[ 'maxValue' ] = 99;
				}
				if( $parenthesis == 4 ) {
					$fieldRule[ 'minValue' ] = 1901;
					$fieldRule[ 'maxValue' ] = 2155;
				}
				$fieldRule[ 'element' ] = "text";
				break;
			
			case 'enum':
				$fieldRule[ 'type' ] = "singleChoice";
				$fieldRule[ 'options' ] = array();
				$options = explode( "','", trim( $parenthesis, "'" ) );
				
				$fieldRule[ 'options' ][ null ] = "Choose Option";
				
				$i = 1;
				foreach( $options as $option ) {
					$fieldRule[ 'options' ][ $i++ ] = $option;
				}
				
				if( is_array( static::$_overrideSchemaRules[ $fieldSchema[ 'Field' ] ][ 'options' ] ) ) {
					foreach( static::$_overrideSchemaRules[ $fieldSchema[ 'Field' ] ][ 'options' ] as $key => $value ) {
						if( $value === null ) unset( $fieldRule[ 'options' ][ $key ] );
						else $fieldRule[ 'options' ][ $key ] = $value;
					}
				}
				
				$fieldRule[ 'element' ] = "select";
				
				break;
			
			default:
				throw new Exception( "FormRecord class does not support the $type field" );
				break;
			
		}
		
		// Set min length for string fields
		if( $fieldRule[ 'type' ] == "string" ) {
			// An assumption is made here that if you don't want text to be null, you are wanting more than an empty zero-length string.
			if( $fieldSchema[ 'Null' ] == "NO" ) $fieldRule[ 'minLength' ] = 1;
			elseif( $fieldSchema[ 'Null' ] == "YES" ) $fieldRule[ 'minLength' ] = 0;
		}
		
		// If this is a boolean field, then make it a singleChoice field with 0 and 1 as options
		if( $fieldRule[ 'type' ] == "integer" && $fieldRule[ 'minValue' ] == 0 && $fieldRule[ 'maxValue' ] == 1 ) {
			$fieldRule[ 'type' ] = "singleChoice";
			
			$fieldRule[ 'options' ] = array(
				null => 'Choose',
				"1" => "Yes",
				"0" => "No"
			);
			
			$fieldRule[ 'element' ] = "select";
			
			unset( $fieldRule[ 'minValue' ] );
			unset( $fieldRule[ 'maxValue' ] );
		}
		
		// Keep track of the auto_increment field
		if( $fieldSchema[ 'Null' ] == "NO" && $fieldSchema[ 'Extra' ] == "auto_increment" ) {
			$fieldRule[ 'required' ] = 0; // auto_increment fields are not required in most cases, so we check for auto_increment in cases where it IS required
			$fieldRule[ 'auto_increment' ] = true;
			$fieldRule[ 'element' ] = "hidden";
		}
		
		// Keep track of the primary key field(s)
		if( $fieldSchema[ 'Key' ] == "PRI" ) {
			$fieldRule[ 'primary_key' ] = true;
			static::$_primaryKeys[] = $fieldSchema[ 'Field' ];
		}
		
		//echo "<pre><font color='red'>".print_r($fieldRule, 1)."</font></pre>";
		//echo "--------------<br>";
		
		return $fieldRule;
	}
	
	public static function createPrettyName( $name )
	{
		return ucwords( preg_replace( "/([^A-Z])([A-Z])/", "$1 $2", $name ) );
	}
	
	public static function getTableName()
	{
		return static::$_table;
	}
	
	public static function getDatabaseName()
	{
		$stmt = static::$_dbh->prepare( "select database() as dbname" );
		$stmt->execute();
		$record = $stmt->fetch( PDO::FETCH_ASSOC );
		return $record[ 'dbname' ];
	}
	
	protected static function findIdColumn( $refTable )
	{
		$stmt = static::$_dbh->prepare( "describe $refTable" );
		$stmt->execute();
		while( $field = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			// An assumption is made here that the first primary key column in the referenced table shall contain the ids for the option
			if( $field[ 'Key' ] == "PRI" ) return $field[ 'Field' ];
		}
		exit;
	}
	
	protected static function findLabelColumn( $refTable )
	{
		$stmt = static::$_dbh->prepare( "describe $refTable" );
		$stmt->execute();
		while( $field = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			list( $refType ) = preg_split( "/[( ]/", $field[ 'Type' ] );
			switch( $refType ) {
				case 'char':
				case 'varchar':
				case 'tinytext':
				case 'text':
				case 'mediumtext':
				case 'longtext':
					// An assumption is made here that the first string column in the referenced table shall contain the labels for the option
					return $field[ 'Field' ];
					break;
			}
		}
	}
	
	protected static function fetchFieldOptions( $refTable, $idColumn, $labelColumn )
	{
		$stmt = static::$_dbh->prepare( "select $idColumn, $labelColumn from $refTable" );
		$stmt->execute();
		
		$options = array();
		
		$options[ null ] = "Choose Option";
		
		while( $record = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$options[ $record[ $idColumn ] ] = $record[ $labelColumn ];
		}
		
		return $options;
	}
	
	public static function changeSchemaRule( $column, $rule, $value )
	{
		static::$_schemaRules[ $column ][ $rule ] = $value;
	}
	
	public static function getSchemaRules()
	{
		return static::$_schemaRules;
	}
	
	public static function showSchemaRules()
	{
		echo "<br><b><i>Schema: </i></b>" . self::getDatabaseName();
		echo "<br><br><b><i>Table: </i></b>" . static::$_table;
		echo "<br><br><b><i>Primary Keys: </i></b>" . implode( ", ", static::$_primaryKeys );
		echo "<br><br><b><i>Field Rules: </i></b><pre>" . print_r( static::$_schemaRules, 1 ) . "</pre>";
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
	
	public static function execute()
	{
		self::prepareArgs( func_get_args(), $sql, $params );
		
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $params );
		
		return $stmt;
	}
	
	public static function fetchRecord()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		$records = self::fetchRecords( $where, $params );
		
		return $records[ 0 ];
	}
	
	public static function fetchRecords()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		if( $where != "" && $where != " limit 1" && $where != " for update" && $where != " limit 1 for update" ) $where = " where $where";
		
		$sql = "select * from " . static::$_table . $where;
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $params );
		
		$records = array();
		$calledClass = get_called_class();
		while( $result = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$record = new $calledClass( $result );
			$record->copyCurrentToOriginal();
			$records[] = $record;
		}
		
		return $records;
	}
	
	public static function fetchRecordForUpdate()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1 for update";
		
		$records = self::fetchRecords( $where, $params );
		
		return $records[ 0 ];
	}
	
	public static function fetchRecordsForUpdate()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " for update";
		
		return self::fetchRecords( $where, $params );
	}
	
	// There shall be no combining of "WithAPC" methods with "ForUpdate" methods into "WithAPCForUpdate", they shall remain mutually exclusive as a best practice:
	// You don't want to APC cache data that you plan to do updates on because that can potentially cause APC data to get out of sync because APC cannot set locks
	// The APC fetch methods should cache data that isn't expected to change often or by multiple threads, which would potentially put APC data out of sync
	public static function fetchRecordWithAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for fetchRecordWithAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		$record = apc_fetch( "dbcache:record:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
		
		if( $record === false ) {
			$records = self::fetchRecords( $where, $params );
			$record = $records[ 0 ];
			apc_store( "dbcache:record:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ), $record );
		}
		
		return $record;
	}
	
	public static function fetchRecordsWithAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for fetchRecordsWithAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$records = apc_fetch( "dbcache:records:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
		
		if( $records === false ) {
			$records = self::fetchRecords( $where, $params );
			apc_store( "dbcache:records:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ), $records );
		}
		
		return $records;
	}
	
	public static function fetchArray()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		$arrays = self::fetchArrays( $where, $params );
		
		return $arrays[ 0 ];
	}
	
	public static function fetchArrays()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		if( $where != "" && $where != " limit 1" ) $where = " where $where";
		
		$sql = "select * from " . static::$_table . $where;
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $params );
		
		$arrays = array();
		while( $result = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$arrays[] = $result;
		}
		
		return $arrays;
	}
	
	public static function fetchArrayWithAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for fetchArrayWithAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		$array = apc_fetch( "dbcache:array:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
		
		if( $array === false ) {
			$arrays = self::fetchArrays( $where, $params );
			$array = $arrays[ 0 ];
			apc_store( "dbcache:array:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ), $array );
		}
		
		return $array;
	}
	
	public static function fetchArraysWithAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for fetchArrayWithAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$arrays = apc_fetch( "dbcache:arrays:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
		
		if( $arrays === false ) {
			$arrays = self::fetchArrays( $where, $params );
			apc_store( "dbcache:arrays:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ), $arrays );
		}
		
		return $arrays;
	}
	
	public static function removeRecordFromAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for removeRecordFromAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		return apc_delete( "dbcache:record:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
	}
	
	public static function removeRecordsFromAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for removeRecordsFromAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		return apc_delete( "dbcache:records:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
	}
	
	public static function removeArrayFromAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for removeArrayFromAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where .= " limit 1";
		
		return apc_delete( "dbcache:array:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
	}
	
	public static function removeArraysFromAPC()
	{
		if( ! APC_EXISTS ) throw new Exception( "APC not loaded for removeArraysFromAPC" );
		
		self::prepareArgs( func_get_args(), $where, $params );
		
		return apc_delete( "dbcache:arrays:" . static::$_dbh->getConnectionSignature() . ":" . get_called_class() . ":" . $where . ":" . serialize( $params ) );
	}
	
	public static function groupByKeys()
	{
		$newResults = array();
		
		$records = array();
		self::prepareArgs( func_get_args(), $records, $keys );
		
		foreach( $records as $record ) {
			$newResultsRef =& $newResults;
			if( is_object( $record ) ) $isObject = true;
			elseif( is_array( $record ) ) $isObject = false;
			else throw new Exception( get_called_class() . "::groupByKeys must operate on an array of objects or an array of arrays" );
			foreach( $keys as $key ) {
				if( $isObject ) $value = $record->$key;
				else $value = $record[ $key ];
				if( $value != null ) {
					if( ! is_array( $newResultsRef[ $value ] ) ) $newResultsRef[ $value ] = array();
					$newResultsRef =& $newResultsRef[ $value ];
				}
				else {
					$newResultsRef[] = array();
					$newResultsRef =& $newResultsRef[ count( $newResultsRef ) - 1 ];
				}
			}
			$newResultsRef = $record;
		}
		
		return $newResults;
	}
	
	public static function countRecords()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		if( $where != "" ) $where = " where $where";
		
		reset( static::$_schemaRules );
		$firstColumn = key( static::$_schemaRules );
		
		$sql = "select count( $firstColumn ) as num from " . static::$_table . $where;
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $params );
		
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		return $result[ 'num' ];
	}
	
	public static function createRecord( $data = array() )
	{
		$calledClass = get_called_class();
		return new $calledClass( $data );
	}
	
	public function save()
	{
		if( count( $this->_originalData ) == 0 ) {
			$this->insert();
		}
		else {
			$this->update();
		}
	}
	
	public function insert()
	{
		$fields = array();
		$formattedValues = array();
		
		$autoIncrementField = null;
		
		foreach( static::$_schemaRules as $field => $rule ) {
			$value = $this->_currentData[ $field ];
			
			$value = ( string ) $value; // This normalizes the value to test for null. For example: 0 == null while "0" != null
			
			if( ! self::validateField( $rule, $value ) ) throw new Exception( self::getRuleMessage( $rule ) );
			
			if( $value != null ) {
				$fields[] = $field;
				$formattedValues[ ":" . $field ] = self::formatValueForDatabase( $rule, $value );
			}
			
			if( $rule[ 'auto_increment' ] == 1 ) $autoIncrementField = $field;
		}
		
		$sql = "insert into " . static::$_table . " ( " . implode( ", ", $fields ) . " ) values ( :" . implode( ", :", $fields ) . " )";
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $formattedValues );
		
		if( $autoIncrementField != null ) $this->_currentData[ $autoIncrementField ] = static::$_dbh->lastInsertId();
		
		// Now that the record in the database has the same data as this record object, we need this record's "_originalData" values to reflect this fact
		$this->copyCurrentToOriginal();
	}
	
	public function update()
	{
		$formattedValues = array();
		
		$where = "";
		foreach( static::$_primaryKeys as $key ) {
			if( $where != "" ) $where .= " and ";
			$where .= "$key = :pk$key"; // Adding pk in the param name will prevent a conflict if the same param is being used as a part of the update clause (in the case where the primary key itself is being updated)
			$rule = static::$_schemaRules[ $key ];
			//if( $this->_originalData[ $key ] == null ) throw new Exception( "Attempting to update a data record without a primary key present: $key" ); // This is moot because this situation cannot even happen, so don't spend resources to process the if statement
			$formattedValues[ ":pk" . $key ] = self::formatValueForDatabase( $rule, $this->_originalData[ $key ] );
		}
		
		$update = "";
		foreach( static::$_schemaRules as $field => $rule ) {
			if( $this->_currentData[ $field ] != $this->_originalData[ $field ] ) {
				$value = $this->_currentData[ $field ];
				
				// Because we are updating a record, the auto_increment field is required, so the rule gets changed temporarily before validation is performed
				if( $rule[ 'auto_increment' ] == 1 ) $rule[ 'required' ] = 1;
				
				if( ! self::validateField( $rule, $value ) ) throw new Exception( self::getRuleMessage( $rule ) );
				if( $update != "" ) $update .= ", ";
				$update .= "$field = :$field";
				$formattedValues[ ":" . $field ] = self::formatValueForDatabase( $rule, $value );
			}
		}
		
		// If $update is an empty string, then abort the update because no changes have been made
		if( $update == "" ) return;
		
		$sql = "update " . static::$_table . " set $update where $where";
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $formattedValues );
		
		// Now that the record in the database has the same data as this record object, we need this record's "_originalData" values to reflect this fact
		$this->copyCurrentToOriginal();
	}
	
	public function insertOrUpdate()
	{
		$fields = array();
		$insertValuePlaceHolders = array();
		$formattedValues = array();
		$fieldsNotKey = array();
		$formattedValuesNotKey = array();
		
		$autoIncrementField = null;
		
		foreach( static::$_schemaRules as $field => $rule ) {
			$value = $this->_currentData[ $field ];
			
			$value = ( string ) $value; // This normalizes the value to test for null. For example: 0 == null while "0" != null
			
			if( ! self::validateField( $rule, $value ) ) throw new Exception( self::getRuleMessage( $rule ) );
			
			if( $value != null ) {
				$fields[] = $field;
				$insertValuePlaceHolders[] = "?";
				$formattedValues[] = self::formatValueForDatabase( $rule, $value );
				if( $rule[ 'primary_key' ] != 1 ) {
					$fieldsNotKey[] = $field;
					$formattedValuesNotKey[] = self::formatValueForDatabase( $rule, $value );
				}
			}
			
			if( $rule[ 'auto_increment' ] == 1 ) $autoIncrementField = $field;
		}
		
		$formattedValues = array_merge( $formattedValues, $formattedValuesNotKey );
		
		$sql = "insert into " . static::$_table . " ( " . implode( ", ", $fields ) . " ) values ( " . implode( ", ", $insertValuePlaceHolders ) . " )";
		$sql .= " on duplicate key update " . implode( " = ?, ", $fieldsNotKey ) . " = ?";
		
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $formattedValues );
		
		if( $autoIncrementField != null ) $this->_currentData[ $autoIncrementField ] = static::$_dbh->lastInsertId();
		
		// Now that the record in the database has the same data as this record object, we need this record's "_originalData" values to reflect this fact
		$this->copyCurrentToOriginal();
	}
	
	public function delete()
	{
		$formattedValues = array();
		
		$where = "";
		foreach( static::$_primaryKeys as $key ) {
			if( $where != "" ) $where .= " and ";
			$where .= "$key = :pk$key"; // Adding pk in the param name will prevent a conflict if the same param is being used as a part of the update clause (in the case where the primary key itself is being updated)
			$rule = static::$_schemaRules[ $key ];
			//if( $this->_originalData[ $key ] == null ) throw new Exception( "Attempting to delete a data record without a primary key present: $key" ); // This is moot because this situation cannot even happen, so don't spend resources to process the if statement
			$formattedValues[ ":pk" . $key ] = self::formatValueForDatabase( $rule, $this->_originalData[ $key ] );
		}
		
		$sql = "delete from " . static::$_table . " where $where";
		$stmt = static::$_dbh->prepare( $sql );
		$stmt->execute( $formattedValues );
		
		$this->_originalData = array();
		$this->_currentData = array();
	}
	
	// TODO: Test the following method for every scenario using SimpleTest
	public static function validateField( $rule, $value )
	{
		if( ! is_array( $value ) ) $value = ( string ) $value; // This normalizes the value to test for null. For example: 0 == null while "0" != null
		
		// If the field is not required and there is no value, it passes instantly, return true now so that it doesn't erroneously fail from later tests
		if( $rule[ 'required' ] == 0  && $value == null ) return true;
		
		if( $rule[ 'required' ] == 1  && $value == null ) return false;
		
		if( $rule[ 'type' ] == "integer"  && ( ( $value === true ) || ! ctype_digit( ltrim( ( string ) $value, "-" ) ) ) ) return false;
		
		if( $rule[ 'type' ] == "decimal" && ! is_numeric( $value ) ) return false;
		
		if( $rule[ 'type' ] == "date" ) {
			$dateParts = preg_split( '/[.-/]/', $value );
			$day = ( int ) $dateParts[ 2 ];
			$month = ( int ) $dateParts[ 1 ];
			$year = ( int ) $dateParts[ 3 ];
			if( checkdate( $month, $day, $year ) == false ) return false;
		}
		
		if( $rule[ 'type' ] == "time" ) {
			preg_match( "/^(\d{1,2}):(\d{2})(:(\d{2}))?(\s?(AM|am|PM|pm))?$/", $value, $timeParts );
			
			$hour = $timeParts[ 1 ];
			$minute = $timeParts[ 2 ];
			$second = $timeParts[ 4 ];
			$ampm = $timeParts[ 6 ];
			
			if( $second == "" ) $second = null;
			if( $ampm == "" ) $ampm = null;
			
			if( $hour < 0  || $hour > 23 ) return false;
			if( $hour > 12 && $ampm != null ) return false;
			if( $minute < 0 || $minute > 59 ) return false;
			if( $second != null && ( $second < 0 || $second > 59 ) ) return false;
		}
		
		if( $rule[ 'type' ] == "singleChoice" ) if( ! array_key_exists( $value, $rule[ 'options' ] ) ) return false;
		
		if( $rule[ 'type' ] == "multiChoice" ) {
			foreach( $value as $option ) {
				if( ! array_key_exists( $option, $rule[ 'options' ] ) ) return false;
			}
		}
		
		if( isset( $rule[ 'minValue' ] ) && $value < $rule[ 'minValue' ] ) return false;
		
		if( isset( $rule[ 'maxValue' ] ) && $value > $rule[ 'maxValue' ] ) return false;
		
		if( isset( $rule[ 'minLength' ] ) && strlen( $value ) < $rule[ 'minLength' ] ) return false;
		
		if( isset( $rule[ 'maxLength' ] ) && strlen( $value ) > $rule[ 'maxLength' ] ) return false;
		
		return self::customValidateField( $rule, $value );
	}
	
	// Override this method in the child class for additional validation rules
	public static function customValidateField( $rule, $value )
	{
		return true;
	}
	
	public static function getRuleMessage( $rule )
	{
		// You can decide exactly what you want to be returned as a field message for a given field by overriding $_customFieldMessages in the child class definition.
		if( isset( static::$_customFieldMessages[ $rule[ 'name' ] ] ) ) return static::$_customFieldMessages[ $rule[ 'name' ] ];
		
		$message = $rule[ 'prettyName' ];
		
		$message .= $rule[ 'required' ] ? " is required and" : " is optional, but";
		
		switch( $rule[ 'type' ] ) {
			case 'integer':
				$message .= " must be a whole number between " . $rule[ 'minValue' ] . " and " . $rule[ 'maxValue' ] . ".";
				break;
			
			case 'string':
				$message .= " cannot be longer than " . $rule[ 'maxLength' ] . " characters.";
				break;
			
			case 'singleChoice':
				$message .= " must be one of the following values: " . implode( ", ", $rule[ 'options' ] );
				break;
			
			case 'multiChoice':
				$message .= " can only have the following values: " . implode( ", ", $rule[ 'options' ] );
				break;
		}
		
		return $message;
	}
	
	public static function getFieldMessage( $field )
	{
		return self::getRuleMessage( static::$_schemaRules[ $field ] );
	}
	
	public static function changeFieldMessage( $field, $message )
	{
		static::$_customFieldMessages[ $field ] = $message;
	}
	
	public static function formatValueForDatabase( $rule, $value )
	{
		if( $rule[ 'type' ] == "datetime" ) return date( "Y-m-d H:i:s", $value );
		
		if( $rule[ 'type' ] == "string" && $value === "" ) return null;
		
		if( $rule[ 'type' ] == "multiChoice" ) {
			if( $value == null ) return null;
			$value = array_unique( $value );
			return "[" . implode( "][", $value ) . "]";
		}
		
		return $value;
	}
	
	public static function formatValueForObject( $rule, $value )
	{
		if( $rule[ 'type' ] == "datetime" ) {
			if( ( $value !== true ) && ctype_digit( ltrim( ( string ) $value, "-" ) ) ) return $value; // $value is an integer, so treat it like a timestamp
			return strtotime( str_replace( array( "-", "." ), "/", $value ) ); // strtotime() interprets hyphens as a MySQL datetime field. We want to treat "-", ".", and "/" the same.
		}
		
		if( $rule[ 'type' ] == "multiChoice" ) {
			if( $value == null ) return null;
			if( is_string( $value ) ) $value = explode( "][", trim( $value, "[]" ) );
			$value = array_unique( $value );
		}
		
		return $value;
	}
	
	public static function insertRecords()
	{
		$records = array();
		$args = func_get_args();
		foreach( $args as $arg ) {
			if( $arg instanceof self ) $arg = $arg->getData();
			if( ! is_array( $arg ) ) continue;
			if( ! is_array( current($arg) ) ) $records[] = $arg;
			else {
				foreach( $arg as $inArg ) {
					if( $inArg instanceof self ) $inArg = $inArg->getData();
					$records[] = $inArg;
				}
			}
		}
		
		$fields = array_keys( static::$_schemaRules );
		
		$sql = "insert into " . static::$_table . " (" . implode( ",", $fields ) . ") values ";
		
		$values = array();
		$valueStrings = array();
		foreach( $records as $record ) {
			$valueSet = array();
			$valueString = "";
			$retainSet = false;
			foreach( $fields as $field ) {
				$valueSet[] = $record[ $field ];
				if( $valueString != "" ) $valueString .= ",";
				$valueString .= "?";
				if( $record[ $field ] != null ) $retainSet = true;
			}
			if( $retainSet ) {
				$values = array_merge( $values, $valueSet );
				$valueStrings[] = "($valueString)";
			}
		}
		
		$sql .= implode( ",", $valueStrings );
		
		$stmt = static::$_dbh->prepare( $sql );
		return $stmt->execute( $values );
	}
	
	public static function updateRecords()
	{
		$args = func_get_args();
		$num = count( $args );
		
		$values = array();
		$where = "";
		$params = array();
		
		for( $i = 0; $i < $num; $i++ ) {
			if( $i == 0 ) $values = $args[ $i ];
			elseif( $i == 1 ) $where = $args[ $i ];
			else {
				if( is_array( $args[ $i ] ) ) $params = array_merge( $params, $args[ $i ] );
				else $params[] = $args[ $i ];
			}
		}
		
		$sql = "update " . static::$_table . " set ";
		
		$fields = array_keys( static::$_schemaRules );
		
		$updateValues = array();
		$updates = "";
		
		foreach( $fields as $field ) {
			if( isset( $values[ $field ] ) ) {
				$updateValues[] = $values[ $field ];
				if( $updates != "" ) $updates .= ", ";
				$updates .= "$field = ?";
			}
		}
		
		$params = array_merge( $updateValues, $params );
		
		$sql .= $updates;
		
		if( $where != "" ) $sql .= " where " . $where;
		
		$stmt = static::$_dbh->prepare( $sql );
		return $stmt->execute( $params );
	}
	
	public static function deleteRecords()
	{
		self::prepareArgs( func_get_args(), $where, $params );
		
		$where = " where $where";
		
		$sql = "delete from " . static::$_table . $where;
		$stmt = static::$_dbh->prepare( $sql );
		return $stmt->execute( $params );
	}
	
	public static function beginTransaction()
	{
		return static::$_dbh->beginTransaction();
	}
	
	public static function rollBack()
	{
		return static::$_dbh->rollBack();
	}
	
	public static function commit()
	{
		return static::$_dbh->commit();
	}
	
	public static function inTransaction()
	{
		return static::$_dbh->inTransaction();
	}
	
	public function label()
	{
		self::prepareArgs( func_get_args(), $fieldName, $settings );
		
		if( isset( static::$_schemaRules[ $fieldName ] ) ) $label = static::$_schemaRules[ $fieldName ][ 'prettyName' ];
		else $label = $settings[ 'prettyName' ];
		
		return $label;
	}
	
	public function input()
	{
		self::prepareArgs( func_get_args(), $fieldName, $settings );
		
		if( isset( static::$_schemaRules[ $fieldName ] ) ) $element = static::$_schemaRules[ $fieldName ][ 'element' ];
		else $element = $settings[ 'element' ];
		
		if( $element == "text" ) return '<input type="text" id="' . $fieldName . '" name="' . $fieldName . '" value="' . $this->_currentData[ $fieldName ] . '" class="field-input-text">';
		if( $element == "password" ) return '<input type="password" id="' . $fieldName . '" name="' . $fieldName . '" value="' . $this->_currentData[ $fieldName ] . '" class="field-input-text">';
	}
	
	public function tableRow()
	{
		self::prepareArgs( func_get_args(), $fieldName, $settings );
		
		// Later, if it helps fix something somewhere for some reason, we could add $settings as a schema rule to this record at this point
		
		return '
			<tr id="' . $fieldName . '-field" class="field">
				<td id="' . $fieldName . '-label" class="field-label">' . $this->label( $fieldName, $settings ) . '</td>
				<td id="' . $fieldName . '-input" class="field-input">' . $this->input( $fieldName, $settings ) . '</td>
				<td id="' . $fieldName . '-status" class="field-status"></td>
			</tr>
		';
	}
	
	// TODO: Create a method here that returns 3 divs side-by-side, which themselves can become float left and placed inside of a single tr > td
	
	// Form validation starter methods intended to cover the majority of validation cases
	// This method allows you to mix multiple FormRecord objects into a single form - TODO: Prove it!
	// This validation system gives much room and flexibility for customization because no validation system can be exhaustive for every scenario
	
	// Invoke FormRecord::validation( <id of the containing html form element>, <FormRecord Object 1>, <FormRecord Object 2>, etc )
	public static function validation()
	{
		self::prepareArgs( func_get_args(), $formId, $records );
		
		// TODO: Filter out data that isn't needed for form validation before adding it to $rules (which gets json_encoded and added to form)
		$rules = array();
		$valid = true;
		foreach( $records as $record ) {
			$schemaRules = $record->getSchemaRules();
			foreach( $schemaRules as $fieldName => $schemaRule ) {
				$rules[ $fieldName ] = $schemaRule;
			}
			
			// If any record is invalid, need to invoke the activateForm call below to display javascript validation immediately after the page is loaded
			if( $record->_valid === false ) $valid = false;
		}
		
		return "
			if( typeof $formId" . "CustomValidation === 'undefined' ) $formId" . "CustomValidation = null;
			if( typeof $formId" . "CustomMessages === 'undefined' ) $formId" . "CustomMessages = null;
			if( typeof $formId" . "UpdatedElements === 'undefined' ) $formId" . "UpdatedElements = null;
			
			$formId" . "Validator = createValidator(
				document.getElementById( '$formId' ),
				" . json_encode( $rules ) . ",
				$formId" . "CustomValidation,
				$formId" . "CustomMessages,
				$formId" . "UpdatedElements
			);
			
			" . ( $valid == false ? "$formId" . "Validator.activateForm();" : "" ) . "
		";
	}
	
	// TODO: Test the following method for every scenario using SimpleTest
	public function isValid()
	{
		foreach( static::$_schemaRules as $field => $rule ) {
			if( self::validateField( $rule, $this->_currentData[ $field ] ) == false ) {
				$this->_valid = false;
				break;
			}
		}
		
		if( $this->_valid !== false ) $this->_valid = true;
		
		return $this->_valid;
	}
	
	public static function ajaxValidateTestFieldValueExists( $fieldName, $value, $fieldPrettyName = null )
	{
		if( static::$_schemaRules[ $fieldName ] == null ) {
			return json_encode( array(
				"valid" => false,
				"message" => "There was a problem with the request."
			));
		}
		else {
			$record = static::fetchRecord( $fieldName . " = ?", $value );
			
			if( $fieldPrettyName == null ) $fieldPrettyName = strtolower( static::$_schemaRules[ $fieldName ][ 'prettyName' ] );
			
			if( $record != null ) {
				$result = array(
					"valid" => false,
					"message" => "This $fieldPrettyName already exists in the system."
				);
			}
			else {
				$result = array( "valid" => true );
			}
			
			return json_encode( $result );
		}
	}
}
