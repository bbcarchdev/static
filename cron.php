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
if(!defined('STATICGEN_INSTANCE'))
{
	define('STATICGEN_INSTANCE', php_uname('n'));
}
if(!defined('STATICGEN_PATH'))
{
	echo "STATICGEN_PATH is not defined, aborting.\n";
	exit(1);
}
if(!defined('STATICGEN_SOURCE_HOST'))
{
	echo "STATICGEN_SOURCE_HOST is not defined, aborting.\n";
	exit(1);
}
if(!is_array($STATICGEN_RSYNC_DEST))
{
	$STATICGEN_RSYNC_DEST = array();
}
if(!defined('STATICGEN_RSYNC_ARGS'))
{
	define('STATICGEN_RSYNC_ARGS', '');
}
if(!defined('RSYNC_PATH'))
{
	define('RSYNC_PATH', 'rsync');
}
global $wpdb;

$old = trim(@file_get_contents(STATICGEN_PATH . '/synced'));
/** Trigger wp-cron **/
delete_transient('doing_cron'); // avoid WP cron locking which assumes local connections work
$ctx = stream_context_create(array('http' => array( 'timeout' => 2400 ) ) );
$buf = file_get_contents('http://' . STATICGEN_SOURCE_HOST . '/wp-cron.php?doing_wp_cron', 0, $ctx);
if ($buf === FALSE)
{
        echo "/wp-cron.php failed!";
        exit(1);
}
if(!file_exists(STATICGEN_PATH . '/current'))
{
	echo STATICGEN_PATH . "/current does not exist, aborting.\n";
	exit(0);
}
$new = readlink(STATICGEN_PATH . '/current');
$errors = array();
if(strcmp($new, $old))
{
	foreach ($STATICGEN_RSYNC_DEST as $dest) {
		$command = escapeshellcmd(RSYNC_PATH) . " -a --delete " . STATICGEN_RSYNC_ARGS . " " . escapeshellarg(STATICGEN_PATH  . '/current/') . " " . escapeshellarg($dest);
		if(defined('STATICGEN_VERBOSE_SYNC') && STATICGEN_VERBOSE_SYNC)
		{
			echo "+ $command\n";
		}
		$ret = 255;
		system($command, $ret);	
		if($ret != 0)
		{
			array_push($errors, $dest);
		}
	}
	if(count($errors) === 0)
	{
		file_put_contents(STATICGEN_PATH . '/synced', $new);
	}
	else
	{
		if(defined('STATICGEN_VERBOSE_SYNC') && STATICGEN_VERBOSE_SYNC)
		{
			echo "errors with targets:\n";
			foreach ($errors as $error)
			{
				echo "  " . $error . "\n";
			}
		}
		exit(count($errors));
	}
}
else
{
	$ret = 0;
}
exit(0);

