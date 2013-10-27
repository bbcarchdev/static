<?php

/* Rebuild the static tree on demand. This script must be executed as the web server user */

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

$inhibit = apply_filters('flagpole', false, 'inhibit-publishing');
if($inhibit)
{
	$this->log('Flagpole "inhibit-publishing is set"');
	exit(0);
}

/** Trigger wp-cron **/
StaticGen::$instance->rebuild();

if(!file_exists(STATICGEN_PATH . '/current'))
{
	echo STATICGEN_PATH . "/current does not exist, aborting.\n";
	exit(1);
}

exit(0);

