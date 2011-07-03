<?php

/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

class SimpleMVC
{
	public static $_view;
	public static $_layoutFolder = LAYOUT_FOLDER;
	public static $_contentFolder = VIEW_FOLDER;
	public static $_layoutFile;
	public static $_contentFile;
	
	public static function showView()
	{
		if( is_file( self::$_contentFolder . self::$_contentFile . ".phtml" ) ) {
			if( is_file( self::$_layoutFolder . self::$_layoutFile . ".phtml" ) ) {
				extract( ( array ) self::$_view );
				include self::$_layoutFolder . self::$_layoutFile . ".phtml";
			}
			else {
				self::showContent();
			}
		}
	}
	
	public static function showContent()
	{
		extract( ( array ) self::$_view );
		include self::$_contentFolder . self::$_contentFile . ".phtml";
	}
}

$view = new stdClass();

SimpleMVC::$_view =& $view;
