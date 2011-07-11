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
include_once LIBRARY_FOLDER . "Auth/Auth.php";

class AuthTest extends UnitTestCase {
	
	private $dbh;
	private $user1;
	private $user2;
	
	function setUp() {
		// Create simpletest database, then create database tables and data and prepare classes for testing
		
		$dbh = new Database( "authtest" );
		
		$dbh->createDatabase( "simpletest" );
		
		$dbh->execute( "
			CREATE TABLE IF NOT EXISTS `users` (
				`userId` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
				`username` VARCHAR(255) NOT NULL ,
				`email` VARCHAR(255) NOT NULL ,
				`password` VARCHAR(255) NOT NULL ,
				`createdAt` DATETIME NULL ,
				`activatedAt` DATETIME NULL ,
				`bannedUntil` DATETIME NULL ,
				PRIMARY KEY (`userId`) ,
				INDEX `IDX_users_username` (`username` ASC) ,
				INDEX `IDX_users_email` (`email` ASC)
			) ENGINE = InnoDB;
		" );
		
		$dbh->execute( "
			CREATE TABLE IF NOT EXISTS `roles` (
				`roleId` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT ,
				`prettyName` VARCHAR(64) NOT NULL ,
				PRIMARY KEY (`roleId`) )
			ENGINE = InnoDB;
		" );
		
		$dbh->execute( "
			CREATE TABLE IF NOT EXISTS `role_privs` (
				`roleId` SMALLINT UNSIGNED NOT NULL ,
				`privId` CHAR(16) NOT NULL ,
				PRIMARY KEY (`roleId`, `privId`) ,
				INDEX `FK_role_privs_role_id` (`roleId` ASC) ,
				CONSTRAINT `FK_role_privs_role_id`
					FOREIGN KEY (`roleId` )
					REFERENCES `roles` (`roleId` )
					ON DELETE CASCADE
					ON UPDATE CASCADE)
			ENGINE = InnoDB;
		" );
		
		$dbh->execute( "
			CREATE TABLE IF NOT EXISTS `user_roles` (
				`userId` INT UNSIGNED NOT NULL ,
				`roleId` SMALLINT UNSIGNED NOT NULL ,
				PRIMARY KEY (`userId`, `roleId`) ,
				INDEX `FK_user_roles_user_id` (`userId` ASC) ,
				INDEX `FK_user_roles_role_id` (`roleId` ASC) ,
				CONSTRAINT `FK_user_roles_user_id`
					FOREIGN KEY (`userId` )
					REFERENCES `users` (`userId` )
					ON DELETE CASCADE
					ON UPDATE CASCADE,
				CONSTRAINT `FK_user_roles_role_id`
					FOREIGN KEY (`roleId` )
					REFERENCES `roles` (`roleId` )
					ON DELETE CASCADE
					ON UPDATE CASCADE)
			ENGINE = InnoDB;
		" );
		
		$dbh->autoGenerateRemainingModels();
		
		$user1 = authtest_Users::createRecord();
		$user1->username = "testuser1";
		$user1->email = "testuser1@test.com";
		$user1->password = "test123";
		$user1->createdAt = time();
		$user1->save();
		
		$user2 = authtest_Users::createRecord();
		$user2->username = "testuser2";
		$user2->email = "testuser2@test.com";
		$user2->password = "test456";
		$user2->createdAt = time();
		$user2->save();
		
		
		$role1 = authtest_Roles::createRecord();
		$role1->prettyName = "SysAdmin";
		$role1->save();
		
		$rolePriv1 = authtest_RolePrivs::createRecord();
		$rolePriv1->roleId = $role1->roleId;
		$rolePriv1->privId = "ADMIN_LOGIN";
		$rolePriv1->save();
		
		$rolePriv2 = authtest_RolePrivs::createRecord();
		$rolePriv2->roleId = $role1->roleId;
		$rolePriv2->privId = "EDIT_USER_PRIVS";
		$rolePriv2->save();
		
		
		$role2 = authtest_Roles::createRecord();
		$role2->prettyName = "Admin";
		$role2->save();
		
		$rolePriv1 = authtest_RolePrivs::createRecord();
		$rolePriv1->roleId = $role2->roleId;
		$rolePriv1->privId = "ADMIN_LOGIN";
		$rolePriv1->save();
		
		$rolePriv2 = authtest_RolePrivs::createRecord();
		$rolePriv2->roleId = $role2->roleId;
		$rolePriv2->privId = "EDIT_CMS";
		$rolePriv2->save();
		
		
		$role3 = authtest_Roles::createRecord();
		$role3->prettyName = "Member";
		$role3->save();
		
		$rolePriv = authtest_RolePrivs::createRecord();
		$rolePriv->roleId = $role3->roleId;
		$rolePriv->privId = "SITE_LOGIN";
		$rolePriv->save();
		
		
		$role4 = authtest_Roles::createRecord();
		$role4->prettyName = "Special Member";
		$role4->save();
		
		$rolePriv1 = authtest_RolePrivs::createRecord();
		$rolePriv1->roleId = $role4->roleId;
		$rolePriv1->privId = "SITE_LOGIN";
		$rolePriv1->save();
		
		$rolePriv2 = authtest_RolePrivs::createRecord();
		$rolePriv2->roleId = $role4->roleId;
		$rolePriv2->privId = "SPECIAL_FEATURE";
		$rolePriv2->save();
		
		
		$userRole = authtest_UserRoles::createRecord();
		$userRole->userId = $user1->userId;
		$userRole->roleId = $role1->roleId;
		$userRole->save();
		$userRole->roleId = $role2->roleId;
		$userRole->insert();
		
		$userRole = authtest_UserRoles::createRecord();
		$userRole->userId = $user2->userId;
		$userRole->roleId = $role2->roleId;
		$userRole->save();
		$userRole->roleId = $role3->roleId;
		$userRole->insert();
		
		
		Auth::init(array(
			'dbPrefix' => "authtest"
		));
		
		
		$this->dbh = $dbh;
		$this->user1 = $user1;
		$this->user2 = $user2;
	}
	
	function testAuthUserPriv() {
		$this->assertIdentical( Auth::userPriv( $this->user1, "ADMIN_LOGIN" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user1, "EDIT_USER_PRIVS" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user1, "EDIT_CMS" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "ADMIN_LOGIN" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "EDIT_USER_PRIVS" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "EDIT_CMS" ), true );
		
		$this->assertIdentical( Auth::userPriv( $this->user1, "SITE_LOGIN" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user1, "SPECIAL_FEATURE" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user1, "NON_EXISTING_PRIV" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "SITE_LOGIN" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "SPECIAL_FEATURE" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user1->userId, "NON_EXISTING_PRIV" ), false );
		
		$this->assertIdentical( Auth::userPriv( $this->user2, "ADMIN_LOGIN" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user2, "EDIT_CMS" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user2, "SITE_LOGIN" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "ADMIN_LOGIN" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "EDIT_CMS" ), true );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "SITE_LOGIN" ), true );
		
		$this->assertIdentical( Auth::userPriv( $this->user2, "EDIT_USER_PRIVS" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user2, "SPECIAL_FEATURE" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user2, "NON_EXISTING_PRIV" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "EDIT_USER_PRIVS" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "SPECIAL_FEATURE" ), false );
		$this->assertIdentical( Auth::userPriv( $this->user2->userId, "NON_EXISTING_PRIV" ), false );
	}
	
	function tearDown() {
		// Drop simpletest database
		
		$stmt = $this->dbh->prepare( "drop database simpletest" );
		$stmt->execute();
	}
}
