<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

include_once LIBRARY_FOLDER . "SimpleTest/autorun.php";
include_once LIBRARY_FOLDER . "Database.php";
include_once LIBRARY_FOLDER . "FormRecord/FormRecord.php";

class FormRecordTest extends UnitTestCase {
	
	private $dbh;
	
	function setUp() {
		// Create simpletest database, then create database tables and FormRecord child classes for testing
		
		$this->dbh = new Database();
		
		$this->dbh->createDatabase( "simpletest" );
		
		FormRecord::init( $this->dbh );
	}
	
	function testGetDatabaseName() {
		$this->assertIdentical( FormRecord::getDatabaseName(), "simpletest" );
	}
	
	function tearDown() {
		// Drop simpletest database
		
		$stmt = $this->dbh->prepare( "drop database simpletest" );
		$stmt->execute();
	}
}
