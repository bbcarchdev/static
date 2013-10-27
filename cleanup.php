<?php

/*
 * Copyright 2012 BBC.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */


ini_set('display_errors', 'On');
error_reporting(E_ALL & ~E_STRICT);

if(isset($argv[1]))
{
	require_once(realpath($argv[1]) . '/wp-config.php');
}
else
{
	require_once(dirname(__FILE__) . '/../../../wp-config.php');
}
if(!defined('THESPACE_INSTANCE'))
{
	define('THESPACE_INSTANCE', php_uname('n'));
}

global $wpdb;

$wpdb->query("INSERT IGNORE INTO " . $wpdb->options . " (`option_name`, `option_value`) VALUES ('_static_instance', '');");
/* Reset _static_instance to an empty string where _static_instance is set to THESPACE_INSTANCE */
$wpdb->query($wpdb->prepare("UPDATE " . $wpdb->options . " SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", '', '_static_instance', THESPACE_INSTANCE));
/* Find out whether the UPDATE changed anything */
$croninst = $wpdb->get_var($wpdb->prepare('SELECT `option_value` FROM ' . $wpdb->options . ' WHERE `option_name` = %s', '_static_instance'));
if(strlen($croninst))
{
	echo "_static_instance is " . $croninst . ", this node is " . THESPACE_INSTANCE . "\n";
}

