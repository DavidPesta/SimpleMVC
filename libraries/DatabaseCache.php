<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

class DatabaseCache
{
	public static function fetchCache( $type = null )
	{
		$cache = array();
		$items = new APCIterator( "user", "/dbcache:$type/" );
		while( $items->valid() ) {
			$item = $items->current();
			$cache[ $item[ 'key' ] ] = $item[ 'value' ];
			$items->next();
		}
		ksort( $cache );
		return $cache;
	}
	
	public static function deleteItem( $key )
	{
		apc_delete( urldecode( $_GET[ 'delete' ] ) );
	}
	
	public static function deleteAllItems()
	{
		$items = new APCIterator( "user", "/dbcache:/" );
		while( $items->valid() ) {
			$item = $items->current();
			apc_delete( $item[ 'key' ] );
			$items->next();
		}
	}
	
	public static function deleteEntireAPC()
	{
		apc_clear_cache( "user" );
	}
	
	public static function cacheManager()
	{
		if( isset( $_GET[ 'delete' ] ) ) {
			if( $_GET[ 'delete' ] == "database-items" ) DatabaseCache::deleteAllItems();
			else DatabaseCache::deleteItem( urldecode( $_GET[ 'delete' ] ) );
			if( headers_sent() ) echo '<meta http-equiv="REFRESH" content="0;url=' . CONTROLLER . '">';
			else header( "Location: " . CONTROLLER );
			exit;
		}
		
		echo "<a href='/" . CONTROLLER . "/delete/database-items'>Clear All Database Cache Items</a><br>";
		
		$cache = DatabaseCache::fetchCache();
		
		foreach( $cache as $key => $value ) {
			echo "<pre><b>$key</b> (<a href='/" . CONTROLLER . "/delete/" . urlencode( $key ) . "'>delete</a>)\n" . print_r( $value, 1 ) . "</pre>";
		}
	}
}
