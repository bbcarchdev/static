<?php
/*
Plugin Name: The Space: Static Site Generator
Author: BBC Archive Development
Author URI: http://www.bbc.co.uk/
Description: Generates a completely static version of the website 
*/

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

if(!defined('STATICGEN_DEBUG'))
{
	if(!defined('STATICGEN_ENV') || STATICGEN_ENV != 'live')
	{
		define('STATICGEN_DEBUG', true);
	}
	else
	{
		define('STATICGEN_DEBUG', false);
	}
}
if(!defined('STATICGEN_INHIBIT_FETCH'))
{
	define('STATICGEN_INHIBIT_FETCH', false);
}
if(!defined('STATICGEN_INSTANCE'))
{
	define('STATICGEN_INSTANCE', php_uname('n'));
}
if(!defined('STATICGEN_INHIBIT_CRON_REBUILD'))
{
	/* By default, allow a periodic rebuild to be triggered by wp-cron. Define
	 * this to true if the rebuild.php script will be invoked externally on
	 * a periodic basis (as the web server user) instead.
	 */
	define('STATICGEN_INHIBIT_CRON_REBUILD', false);
}
if(!defined('STATICGEN_REBUILD_ON_SAVE'))
{
	/* By default, a 'build' event (i.e., in-place update of recent changes)
	 * will be invoked via wp-cron. If STATICGEN_REBUILD_ON_SAVE is true,
	 * the actual build process will occur as part of the save_post handler
	 */
	define('STATICGEN_REBUILD_ON_SAVE', false);
}


class StaticGen
{
	protected $siteUrl = array();
	protected $subdir = 'current';
	protected $types = array();
	protected $start = null;
	protected $pid = null;
	protected $deferList = null;
	protected $baseTime = 0;
	protected $utc;
	
	public $building = false;
	
	public static $instance;
	
	public function __construct()
	{	
		/* Invoke this with a low priority to give plugins a chance to
		 * hook the process.
		 */
		self::$instance = $this;
		add_action('admin_init', array($this, 'admin_init'));
		if(!defined('STATICGEN_PATH'))
		{
			return;
		}
		add_action('init', array($this, 'init'), 1000);
		$this->utc = new DateTimeZone('UTC');
		@$st = stat(STATICGEN_PATH . '/.thespace-app-installed');
		$this->baseTime = null;
		if(is_array($st) && isset($st['mtime']))
		{
			$this->baseTime = $st['mtime'];
		}
	}
	
	/* This method is public so that plugins can invoke it for debug-logging */
	public function log()
	{
		if(!STATICGEN_DEBUG) return;
		if($this->start === null)
		{
			$this->start = microtime(true);
		}
		if($this->pid === null)
		{
			$this->pid = getmypid();
		}
		$args = func_get_args();
		$elapsed = microtime(true) - $this->start;
		error_log(sprintf("[%s] %02.03ds %s", $this->pid, $elapsed, implode(' ', $args)));
	}
	
	/* Late-stage initialisation */
	public /*callback*/ function init()
	{
		$this->registerMIMEType('text/html', array('ext' => '.html', 'hidden' => true));
		do_action('staticgen_init', $this);
		/* Phases */		
		add_action('staticgen_rebuild_phase', array($this, 'staticgen_rebuild_taxonomies'));
/*		add_action('staticgen_rebuild_phase', array($this, 'staticgen_rebuild_users')); */
		add_action('staticgen_rebuild_phase', array($this, 'staticgen_rebuild_archives'));
		add_action('staticgen_rebuild_phase', array($this, 'staticgen_rebuild_feeds'));
		add_action('staticgen_rebuild_phase', array($this, 'staticgen_rebuild_posts'), 200, 1);
		/* Cron actions */
		add_action('staticgen_build', array($this, 'staticgen_build'));
		if(!STATICGEN_INHIBIT_CRON_REBUILD)
		{
			add_action('staticgen_rebuild', array($this, 'staticgen_rebuild'));
			add_action('staticgen_rebuild_periodic', array($this, 'staticgen_rebuild'));
		}
		/* WordPress actions */
		add_action('save_post', array($this, 'save_post'), 255);
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action('wp_footer', array($this, 'wp_footer'), 255);
		/* Callable actions */
		add_action('static_update_object', array($this, 'static_update_object'), 10, 4);
		add_action('static_update_post', array($this, 'static_update_post'));	
		if(!STATICGEN_INHIBIT_CRON_REBUILD && !wp_next_scheduled('staticgen_rebuild_periodic'))
		{
			$this->log('Installing periodic rebuild cron job');
			// Run at 25 minutes past the hour/5 minutes to the hour
			$initial = ((floor(time() / 1800) + 1) * 1800) - 300;
			wp_schedule_event($initial, 'halfhourly', 'staticgen_rebuild_periodic');
		}
		
	}
	
	public /*callback*/ function admin_init()
	{
		add_settings_section('staticgen', 'Static site generation', array($this, 'admin_settings_section'), 'permalink');
	}
	
	public /*callback*/ function admin_settings_section()
	{
		if(!defined('STATICGEN_PATH'))
		{
			echo '<div class="error"><p><strong>Static site generation:</strong> <code>STATICGEN_PATH</code> is not defined in <code>wp-config.php</code>. It must be set to the absolute path of the directory where the static tree will be written.</p></div>';
		}
		else if(!file_exists(STATICGEN_PATH))
		{
			echo '<div class="error"><p><strong>Static site generation:</strong> The path named by <code>STATICGEN_PATH</code> does not exist. It must refer to a directory which is writeable by the web server user.</p></div>';		
		}
		else if(!is_dir(STATICGEN_PATH))
		{
			echo '<div class="error"><p><strong>Static site generation:</strong> The path named by <code>STATICGEN_PATH</code> is not a directory.</p></div>';		
		}
		else if(function_exists('posix_access') && !posix_access(STATICGEN_PATH, POSIX_W_OK))
		{
			echo '<div class="error"><p><strong>Static site generation:</strong> The path named by <code>STATICGEN_PATH</code> is not writeable by the web server.</p></div>';		
		}
		$permalink = get_option('permalink_structure');
		if(!strlen($permalink) || strpos($permalink, '?') !== false)
		{
			echo '<div class="error"><p><strong>Static site generation:</strong> The permalink structure includes URL parameters, which cannot be used with the static site generator. Select a different permalink style to enable site generation.</p></div>';			
		}
		echo '<p>Settings for the static site generator are defined in <code>wp-config.php</code>. Their current values are shown below:&mdash;</p>';
		$defines = array('STATICGEN_PATH', 'STATICGEN_SOURCE_HOST', 'STATICGEN_PUBLIC_HOST', 'STATICGEN_DEBUG', 'STATICGEN_INHIBIT_FETCH', 'STATICGEN_INHIBIT_CRON_REBUILD', 'STATICGEN_REBUILD_ON_SAVE', 'STATICGEN_INSTANCE');
		echo '<table class="widefat">';
		echo '<thead>';
		echo '<tr><th scope="col">Name</th><th scope="col">Value</th></tr>';
		echo '</thead>';
		echo '<tbody>';
		foreach($defines as $name)
		{
			echo '<tr><th scope="row"><code>'. htmlspecialchars($name) . '</code></th>';
			if(!defined($name))
			{
				echo '<td class="undefined"><i>Undefined</i></td>';
			}
			else
			{
				$value = constant($name);
				if(is_bool($value))
				{
					echo '<td>' . ($value ? 'True' : 'False') . '</td>';
				}
				else
				{
					echo '<td><code>' . htmlspecialchars($value) . '</code></td>';
				}
			}
			echo '</tr>';
		}
		echo '<tr><th scope="row">Permalink structure:</th><td><code>' . htmlspecialchars($permalink) . '</code></td></tr>';
		echo '</tbody>';
		echo '</table>';
	}
	
	public function touch($post)
	{
		global $wpdb;
		
		if(!is_object($post))
		{
			$post = get_post($post);
		}
		if(!is_object($post))
		{
			return;
		}
		$post_modified = current_time('mysql');
        $post_modified_gmt = current_time('mysql', 1);
        $this->log('post_modified = ' . $post_modified . ', post_modified_gmt = ' . $post_modified_gmt . ', postId = ' . $post->ID);
		$wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->posts . ' SET `post_modified` = %s, `post_modified_gmt` = %s WHERE `ID` = %d', $post_modified, $post_modified_gmt, $post->ID));
		do_action('staticgen_touch', $post);
	}
	
	public /*callback*/ function wp_footer()
	{
		$host = explode('.', php_uname('n'));
		echo '<!-- built on ' . $host[0] . ' at ' . gmstrftime('%Y%m%dT%H%M%SZ') . '-->' . "\n";
	}
	
	public /*callback*/ function cron_schedules($schedules)
	{
		$schedules['halfhourly'] = array(
			'interval' => 1800,
			'display' => __('Half-hourly'),
		);
		return $schedules;
	}

	/* Invoked when a post is updated within the CMS */
	public /*callback*/ function save_post($postId)
	{
		if($this->building)
		{
			return;
		}
		$inhibit = apply_filters('flagpole', false, 'inhibit-inplace');
		if($inhibit)
		{
			$this->log('Flagpole "inhibit-inplace is set"');
			return;
		}
		if(!$postId)
		{
			return;
		}
		$info = get_post($postId);
		if(!is_array($info) && !is_object($info))
		{
			return;
		}
		switch($info->post_status)
		{
			case 'publish':
			case 'private':
			case 'draft':
			case 'future':
			case 'pending':
				break;
			default:
				return;	
		}
		/* Trigger any 'touch' hooks (which may cause other objects to be
		 * touched, so that they will be included in this build pass)
		 */
		$this->touch($info);
		if(STATICGEN_REBUILD_ON_SAVE)
		{
			$this->staticgen_build();
		}
		else
		{
			/* Schedule an update to happen soon */
			wp_schedule_single_event(time(), 'staticgen_build');
		}
	}
	
	public /*callback*/ function staticgen_rebuild()
	{
		$this->log('Beginning periodic rebuild');
		$this->rebuild();
	}

	
	/* Update any posts which have changed since the last rebuild */
	public /*callback*/ function staticgen_build()
	{
		global $wpdb;
		
		set_time_limit(0);
		$this->building = true;
		$this->subdir = 'current';
		$this->deferList = null;
		$this->log('Updating static tree');
		if(!file_exists(STATICGEN_PATH . '/' . $this->subdir))
		{
			if(!STATICGEN_INHIBIT_CRON_REBUILD)
			{
				/* Only rebuild immediately if a full rebuild isn't part of an
				 * externally-triggered process.
				 */
				$this->log('Previous static tree has yet to be populated; rebuilding immediately');
				$this->rebuild();			 
			}
			else
			{
				$this->log('Previous static tree has yet to be populated');
			}
			$this->building = false;
			return;
		}
		$lastInfo = @stat(STATICGEN_PATH . '/build-stamp');
		$last = 0;
		if(is_array($lastInfo))
		{
			$last = $lastInfo['mtime'];			
		}
		if($last)
		{
			$this->log('Last build timestamp is ' . strftime('%Y-%m-%d %H:%M:%S', $last));
			$query = $wpdb->prepare('SELECT ID FROM ' . $wpdb->posts . ' WHERE `post_status` IN (%s, %s, %s, %s, %s) AND `post_modified_gmt` >= %s', 'publish', 'private', 'draft', 'future', 'pending', strftime('%Y-%m-%d %H:%M:%S', $last));
		}
		else
		{
			$query = $wpdb->prepare('SELECT * FROM ' . $wpdb->posts . ' WHERE `post_status` IN (%s, %s, %s, %s, %s)', 'publish', 'private', 'draft', 'future', 'pending');
		}
		$this->log($query);
		$posts = $wpdb->get_col($query);
		$this->log(count($posts) . ' objects will be updated');
		if(count($posts))
		{
			foreach($posts as $postId)
			{
				$this->updatePost($postId, false);
			}
			/* Always update the homepage */
			$this->fetchAndStore('/');
		}
		$f = fopen(STATICGEN_PATH . '/build-stamp', 'w');
		fclose($f);
		$this->log('Static tree update completed');
		$this->building = false;
	}
	
	protected function lockInstance()
	{
		$wpdb->query("INSERT IGNORE INTO " . $wpdb->options . " (`option_name`, `option_value`) VALUES ('_static_instance', '');");			
		/* Reset _static_instance to STATICGEN_INSTANCE  where _static_instance is an empty string*/
		$wpdb->query($wpdb->prepare("UPDATE " . $wpdb->options . " SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", STATICGEN_INSTANCE, '_static_instance', ''));
		/* Check that $croninst matches STATICGEN_INSTANCES following the UPDATE -- if not, bail out */
		$croninst = $wpdb->get_var($wpdb->prepare('SELECT `option_value` FROM ' . $wpdb->options . ' WHERE `option_name` = %s', '_static_instance'));		
		if(strcmp($croninst, STATICGEN_INSTANCE))
		{
			$this->log("_static_instance is " . $croninst . ", this node is " . STATICGEN_INSTANCE . ", aborting publishing run");
			return false;
		}
		return true;
	}
	
	protected function unlockInstance()
	{
		$wpdb->query($wpdb->prepare("UPDATE " . $wpdb->options . " SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", '', '_static_instance', STATICGEN_INSTANCE));
	}
	
	/* Regenerate the static version of the entire site, removing the
	 * previous version.
	 */
	public function rebuild()
	{
		global $wpdb;

		if(!$this->lockInstance())
		{
			return;
		}
		$inhibit = apply_filters('flagpole', false, 'inhibit-publishing');
		if($inhibit)
		{
			$this->log('Flagpole "inhibit-publishing is set"');
			$this->unlockInstance();
			return;
		}
		set_time_limit(0);
		$prev = @readlink(STATICGEN_PATH . '/current');
		if(strlen($prev))
		{
			if(substr($prev, 0, 1) != '/')
			{
				$prev = realpath(STATICGEN_PATH . '/' . $prev);
			}
		}
		$this->log('Beginning static rebuild -', 'previous build ID=' . $prev);
		if($this->baseTime === null)
		{
			$this->log('Warning: cannot determine base time, build will always be from-scratch');
		}
		if(!file_exists(STATICGEN_PATH . '/cache'))
		{
			mkdir(STATICGEN_PATH . '/cache', 0775, true);
			chmod(STATICGEN_PATH . '/cache', 0775);
		}
		$this->subdir = substr(md5(microtime() . getmypid() . rand()), 0, 8);
		mkdir(STATICGEN_PATH . '/' . $this->subdir, 0775, true);
		chmod(STATICGEN_PATH . '/' . $this->subdir, 0775);
		$this->log('New build ID=' . $this->subdir);
		/* Defer all of the local fetches to aid in profiling */
		$deferPath = STATICGEN_PATH . '/' . $this->subdir . '/defer';
		$this->deferList = fopen($deferPath, 'w');
		$this->writeTypeMap(STATICGEN_PATH . '/' . $this->subdir . '/index.var', '');
		$this->buildSymlinks();
		$this->log('Target path prepared');
		do_action('staticgen_pre_rebuild', $this);
		$this->log('Pre-rebuild complete');
		$this->performAction('staticgen_rebuild_phase', $this);
		$this->log('Rebuild phases complete');
		fclose($this->deferList);
		$this->deferList = null;
		$this->processDeferred($deferPath);		
		do_action('staticgen_post_rebuild', $this);
		$this->log('Post-rebuild complete');
		@unlink(STATICGEN_PATH . '/current');
		symlink(realpath(STATICGEN_PATH . '/' . $this->subdir), STATICGEN_PATH . '/current');
		file_put_contents(STATICGEN_PATH . '/current/.published', $this->subdir . ':' . time() . ":" . $this->getHostname());
		$this->subdir = 'current';
		$this->log('New build', $this->subdir, 'is now active');
		if(strlen($prev))
		{
			$this->recursiveRemove($prev);
			$this->log('Removal of', $prev, 'complete');
		}
		$this->unlockInstance();
		$f = fopen(STATICGEN_PATH . '/build-stamp', 'w');
		fclose($f);
		$this->log('Rebuild complete');
	}
	
	protected function processDeferred($path)
	{		
		$this->deferList = null;
		if(STATICGEN_INHIBIT_FETCH)
		{
			$this->log('Instance is configured to inhibit fetches');
			return;
		}
		$this->log('Processing deferred fetches');
		$f = fopen($path, 'r');
		$c = 0;
		while(($row = fgets($f)) !== false)
		{
			$row = json_decode(trim($row), true);
			$c++;
			$this->fetchAndStore($row['u'], $row['e'], $row['c']);
		}
		fclose($f);
		$this->log('Processed ' . $c . ' deferred fetches');
		unlink($path);
	}

	/* Perform an action as do_action does, but with additional logging & wrapping */
	protected function performAction($tag, $arg = '')
	{
		global $wp_filter, $wp_actions, $merged_filters, $wp_current_filter;
	
		if ( ! isset($wp_actions) )
			$wp_actions = array();
	
		if ( ! isset($wp_actions[$tag]) )
			$wp_actions[$tag] = 1;
		else
			++$wp_actions[$tag];
	
		// Do 'all' actions first
		if ( isset($wp_filter['all']) ) {
			$wp_current_filter[] = $tag;
			$all_args = func_get_args();
			_wp_call_all_hook($all_args);
		}
	
		if ( !isset($wp_filter[$tag]) ) {
			if ( isset($wp_filter['all']) )
				array_pop($wp_current_filter);
			return;
		}
	
		if ( !isset($wp_filter['all']) )
			$wp_current_filter[] = $tag;
	
		$args = array();
		if ( is_array($arg) && 1 == count($arg) && isset($arg[0]) && is_object($arg[0]) ) // array(&$this)
			$args[] =& $arg[0];
		else
			$args[] = $arg;
		for ( $a = 2; $a < func_num_args(); $a++ )
			$args[] = func_get_arg($a);
	
		// Sort
		if ( !isset( $merged_filters[ $tag ] ) ) {
			ksort($wp_filter[$tag]);
			$merged_filters[ $tag ] = true;
		}
	
		reset( $wp_filter[ $tag ] );
		$this->log('Performing action:', $tag);
		do {
			foreach ( (array) current($wp_filter[$tag]) as $the_ )
				if ( !is_null($the_['function']) )
				{
					if(is_array($the_['function']) && is_object($the_['function'][0]))
					{
						$display = get_class($the_['function'][0]) . '::' . $the_['function'][1];
					}
					else if(is_array($the_['function']))
					{
						$display = implode('::', $the_['function']);
					}
					else
					{
						$display = $the_['function'];
					}
					$this->log('Invoking', $display . '()', 'for', $tag);
					call_user_func_array($the_['function'], array_slice($args, 0, (int) $the_['accepted_args']));
					$this->log('Completed', $display . '()', 'for', $tag);
				}
	
		} while ( next($wp_filter[$tag]) !== false );
		array_pop($wp_current_filter);		
		$this->log('Completed action:', $tag);
	}

	/* Write all posts, invoked by staticgen_rebuild() via the staticgen_rebuild_phase hook */
	public /*callback*/ function staticgen_rebuild_posts($instance)
	{
		global $wpdb;
		static $statusList = array('publish', 'future', 'private', 'pending');

		$this->performAction('staticgen_rebuild_posts_begin', $instance);
		$permalink = get_option('permalink_structure');
		$permalink = str_replace('%postname%', '', $permalink);
		if(strpos($permalink, '%') === false)
		{		
			$object = (object) null;
			$object->object_type = 'posts';
			$object->link = $permalink;
			$this->updateObject($object, null, $permalink);
		}
		foreach($statusList as $status)
		{
			$list = $wpdb->get_col($wpdb->prepare('SELECT `ID` FROM ' . $wpdb->posts . ' WHERE `post_status` = %s', $status));
			$this->log('Updating', count($list), 'posts');
			$c = 0;
			foreach($list as $post)
			{
				$c++;		
				$this->updatePost($post, true);
				if(!($c % 20))
				{
					$this->log('Updated', $c, 'posts');
				}
			}
		}
		$this->performAction('staticgen_rebuild_posts_complete', $instance);
	}

	/* Write all user pages, invoked by staticgen_rebuild() via the staticgen_rebuild_phase hook */
	/* XXX Currently disabled */
	public /*callback*/ function staticgen_rebuild_users($instance)
	{
		global $wpdb;

		$list = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT `post_author` FROM ' . $wpdb->posts));
		$root = $this->siteUrl();
		if(substr($root, -1) != '/')
		{
			$root .= '/';
		}
		foreach($list as $user)
		{
			$this->updateUser($user, $root);
		}
	}
	
	/* Write all of the taxonomies, invoked by staticgen_rebuild() via the staticgen_rebuild_phase hook */
	public /*callback*/ function staticgen_rebuild_taxonomies($instance, $root = null)
	{
		$taxonomies = get_taxonomies();
		$root = $this->siteUrl();
		if(substr($root, -1) != '/')
		{
			$root .= '/';
		}
		foreach($taxonomies as $tax)
		{
			$this->log('Building ' . $tax);
			$obj = get_taxonomy($tax);
			if(empty($obj->public) || !isset($obj->rewrite) || !strlen($obj->rewrite['slug']))
			{
				continue;
			}
			$base = $root . $obj->rewrite['slug'];
			$obj->object_type = 'taxonomy';
			$fallback = array('text/html' => $base);
			$this->updateObject($obj, null, $base, $fallback);
			$terms = get_terms($obj->name);
			foreach($terms as $k => $v)
			{
				$link = get_term_link($v);
				$v->object_type = 'term';
				$v->taxonomy_object = $obj;
				$v->link = $link;
				$fallback = array('text/html' => $link);
				$this->updateObject($v, null, $link, $fallback);
			}
		}
	}

	/* Write the static archives, invoked by staticgen_rebuild() via the staticgen_rebuild_phase hook */
	public /*callback*/ function staticgen_rebuild_archives($instance)
	{
		global $wpdb;

		$earliest = $wpdb->get_var($wpdb->prepare('SELECT MIN(`post_date`) FROM ' . $wpdb->posts . ' WHERE `post_status` = %s', 'publish'));
		$year = intval(substr($earliest, 0, 4));
		$thisyear = intval(strftime('%Y'));
		for($y = $year; $y <= $thisyear; $y++)
		{
			$this->updateYearArchive($y);
		}
	}

	/* Write all of the RSS/Atom feeds, invoked by staticgen_rebuild() via the staticgen_rebuild_phase hook */
	public /*callback*/ function staticgen_rebuild_feeds($instance)
	{
		$feeds = array(
			'/feed' => '.rss',
			'/feed/rss' => '.rss',
			'/feed/rss2' => '.rss',
			'/feed/atom' => '.atom',
			'/feed/rdf' => '.rdf',
			);
		foreach($feeds as $uri => $defaultExt)
		{
			$this->fetchAndStore($uri, $defaultExt);
		}
	}
	
	/* Update a post */
	public /*callback*/ function static_update_post($post)
	{
		$this->log('Updating post: ' . $post->post_name);
		$this->updatePost($post, false);
	}
	
	public /*callback*/ function static_update_object($object, $custom, $permalink, $fallback = null)
	{
		$this->log('Updating object: ' . $permalink);
		$this->updateObject($object, $custom, $permalink, $fallback);
	}

	
	/* Register a MIME type */
	public function registerMIMEType($type, $info)
	{
		$info['type'] = $type;
		if(!isset($info['serveAs']))
		{
			$info['serveAs'] = $type;
		}
		if(!isset($info['hidden']))
		{
			$info['hidden'] = false;
		}
		$this->types[$type] = $info;
	}
	

	/* Return the site URL for a given site */
	public function siteUrl($blogId = -1)
	{
		global $blog_id;
		
		if($blogId == -1)			
		{
			if(empty($blog_id) || !is_multisite())
			{
				$blogId = 0;
			}
			else
			{
				$blogId = $blog_id;
			}
		}
		if(isset($this->siteUrl[$blogId]))
		{
			return $this->siteUrl[$blogId];
		}
		if(empty($blogId))
		{
			$siteurl = get_option('siteurl');
		}
		else
		{
			$siteurl = get_blog_option($blogId, 'siteurl');
		}
		/* Ensure the site URL always has a trailing slash */
		if(substr($siteurl, -1) != '/')
		{
			$siteurl .= '/';
		}
		$this->siteUrl[$blogId] = $siteurl;
		return $siteurl;
	}
	
	/* Return the absolute source URL, including host, for a given path */
	public function sourceUrl($sourceUrl)
	{
		$url = parse_url($sourceUrl);
		if(isset($url['query']))
		{
			return null;
		}
		/* Allow the source hostname to be overridden */
		if(defined('STATICGEN_SOURCE_HOST'))
		{
			$sourceUrl = 'http://' . STATICGEN_SOURCE_HOST . $url['path'];
		}
		else if(!isset($url['host']) || !strlen($url['host']))
		{
			$siteUrl = $this->siteUrl();
			$sourceUrl = $siteUrl . $url['path'];
		}
		return $sourceUrl;
	}

	/* For a given source URL, determine the filesystem path relative to
	 * build root that the resource should be written to.
	 */
	public function destPath($sourceUrl, $defaultExt = '.html', $relativePath = true)
	{
		$url = parse_url($sourceUrl);
		if(isset($url['query']))
		{
			return null;
		}
		$path = $url['path'];
		while(substr($path, -1) == '/')
		{
			$path = substr($path, 0, -1);
		}
		if(!strlen($path))
		{
			$path = '/index' . $defaultExt;
		}
		if(substr($path, 0, 1) != '/')
		{
			$path = '/' . $path;
		}
		$info = pathinfo($path);
		if(!isset($info['extension']))
		{
			$path .= '/index' . $defaultExt;
		}
		if($relativePath)
		{
			return $path;
		}
		return STATICGEN_PATH . '/' . $this->subdir . $path;
	}

	/* For a given source URL, determine the canonical Content-Location
	 * path for that resource.
	 */
	public function contentPath($sourceUrl, $defaultExt = '.html')
	{
		$url = parse_url($sourceUrl);
		if(isset($url['query']))
		{
			return null;
		}
		$path = $url['path'];
		while(substr($path, -1) == '/')
		{
			$path = substr($path, 0, -1);
		}
		if(!strlen($path))
		{
			$path = '/index' . $defaultExt;
		}
		if(substr($path, 0, 1) != '/')
		{
			$path = '/' . $path;
		}
		$info = pathinfo($path);
		if(!isset($info['extension']))
		{
			$path .= $defaultExt;
		}
		return $path;
	}

	/* Create a directory hierarchy, adding type-maps as needed */
	public function mkdir($destDir)
	{
		$realDir = realpath($destDir);
		$base = realpath(STATICGEN_PATH . '/' . $this->subdir);
/*		if(strncmp($destDir, $base, strlen($base)) && strncmp($realDir, $base, strlen($base)))
		{
			throw new Exception('Invalid destDir specified in call to StaticGen::mkdir(): ' . $destDir);
		} */
//		echo "<pre>[mkdir: destDir=$destDir]</pre>\n";
		if(!file_exists($destDir))
		{					
//			echo "<pre>[mkdir: CREATING $destDir]</pre>\n";
			mkdir($destDir, 0775, true);
		}
		$destDir = realpath($destDir);
		$components = explode('/', $destDir);
		if(!strcmp($destDir, $base))
		{
			chmod($base, 0775);
			$this->writeTypeMap($base . '/index.var', '');
			return;
		}		
		$first = true;
		$last = $components[count($components) - 1];
		while(strlen($destDir))
		{			
			chmod($destDir, 0775);
			$destDir = realpath($destDir . '/..');
			if($first)
			{
				$this->writeTypeMap($destDir . '/' . $last . '.var', $last . '/');
			}
			if(!strcmp($destDir, $base))
			{
				break;
			}
			$first = false;
		}
	}
	
	/* Update the static version of some object (of any sort) */
	public function updateObject($object, $custom, $permalink, $fallback = null, $useCache = false)
	{
		if($useCache && isset($object->post_modified_gmt) && isset($this->baseTime))
		{
			if($object->object_type == 'post' && isset($object->ID) && get_post_meta($object->ID, 'nocache', true))
			{
				$cacheTime = null;			
			}
			else
			{
				$dt = new DateTime($object->post_modified_gmt, $this->utc);			
				$cacheTime = apply_filters('static_post_modified', $dt->getTimestamp(), $object);
			}
		}
		else
		{
			/* No cache, or object isn't cacheable */
			$cacheTime = null;
		}
		$handled = array();
		if(!strncmp($permalink, 'http:', 5) || !strncmp($permalink, 'https:', 6))
		{
			$i = parse_url($permalink);
			$permalink = @$i['path'];
			if(substr($permalink, 0, 1) != '/')
			{
				$permalink = '/' . $permalink;
			}
		}
		foreach($this->types as $type => $typeinfo)
		{
			if($type == 'text/html' && isset($custom['redirect'][0]))
			{
				$location = $custom['redirect'][0];				
				if(strpos($location, ':') === false)
				{
					while(substr($location, 0, 1) == '/')
					{
						$location = substr($location, 1);
					}
					$location = STATICGEN_PUBLIC_HOST . $location;
				}
				$this->writeContentForLink($permalink, '', array(
					'Status' => '301 Moved',
					'Location' => $location,
				), $typeinfo['ext']);
				continue;
			}
			$representation = apply_filters('static_representation', null, $object, $typeinfo, $custom, $permalink, $this->contentPath($permalink, $typeinfo['ext']));
			if($representation === null)
			{
				if(is_array($fallback) && isset($fallback[$type]))
				{
					$this->fetchAndStore($fallback[$type], $typeinfo['ext'], $cacheTime);
				}
				continue;
			}
			$handled[] = $type;
			if($representation === false)
			{
				/* Explicitly do nothing */
			}
			else if(is_array($representation))
			{
				$headers = array(
					'Status' => '404 Not Found',
				);
				$body = '';
				if(isset($representation['headers']))
				{
					$headers = $representation['headers'];
				}
				if(isset($representation['body']))
				{
					$body = $representation['body'];
				}
				$this->writeContentForLink($permalink, $body, $headers, $typeinfo['ext']);
			}
			else
			{
				$headers = array(
					'Status' => '200 OK',
					'Content-Type' => $typeinfo['serveAs'],
				);
				$this->writeContentForLink($permalink, $representation, $headers, $typeinfo['ext']);	
			}
		}
		return $handled;
	}


	/* Write a type-map document at a given filesystem path */
	protected function writeTypeMap($path, $uriPrefix)
	{
//		echo "<pre>[writeTypeMap: path=$path, uriPrefix=$uriPrefix]</pre>\n";
		$f = fopen($path, 'w');
		chmod($path, 0666);
		fwrite($f, "Content-Type: text/html;q=1.0\n");
		fwrite($f, "URI: " . $uriPrefix . "index.html.asis\n");
		fwrite($f, "\n");
		foreach($this->types as $mime => $typeinfo)
		{
			if(!empty($typeinfo['hidden']))
			{
				continue;
			}
			$mime = isset($typeinfo['negotiateAs']) ? $typeinfo['negotiateAs'] : $mime;
			fwrite($f, "Content-Type: " . $mime . ";q=0.9\n");
			fwrite($f, "URI: " . $uriPrefix . "index" . $typeinfo['ext'] . ".asis\n");
			fwrite($f, "\n");
		}
		fclose($f);
	}

	/* Write $content, notionally matching that at $sourceUrl, to the filesystem */
	protected function writeContentForLink($sourceUrl, $content, $headers, $defaultExt = '.html')
	{
		$path = $this->destPath($sourceUrl, $defaultExt);
		if($path === null)
		{
			return;
		}
		if(!isset($headers['Status']))
		{
			$headers['Status'] = '200 OK';
		}
		if(!isset($headers['Content-Location']) && !isset($headers['Location']))
		{
			$headers['Content-Location'] = $this->contentPath($sourceUrl, $defaultExt);
		}
		$buf = array();
		foreach($headers as $key => $value)
		{
			$buf[] = $key . ': ' . $value;
		}
		$buf[] = '';
		$buf[] = $content;
		$destPath = STATICGEN_PATH . '/' . $this->subdir . $path;
		$destDir = dirname($destPath);
//		echo "<pre>[writeContentForLink: destPath = " . $destPath . ", destDir = " . $destDir . "]</pre>\n";
		$this->mkdir($destDir);
		file_put_contents($destPath . '.asis', implode("\n", $buf));
		chmod($destPath . '.asis', 0666);
	}		

	/* Update an individual static resource which will be retrieved from the source */
	protected function fetchAndStore($sourceUrl, $defaultExt = '.html', $cacheTime = null)
	{
		if(isset($this->deferList))
		{
			fwrite($this->deferList, json_encode(array('u' => $sourceUrl, 'e' => $defaultExt, 'c' => $cacheTime)) . "\n");
			return;
		}
		$u = $sourceUrl;
		$sourceUrl = $this->sourceUrl($sourceUrl);
		if($sourceUrl === null)
		{
			$this->log('Failed to determine source URL for ' . $u);
			return;
		}
		$buf = null;
		$loadedFromCache = false;
		$cacheKey = md5($sourceUrl);
		$cachePath = STATICGEN_PATH . '/cache/' . $cacheKey;
		if(isset($cacheTime))
		{
			@$st = stat($cachePath);
			if(is_array($st) && isset($st['mtime']))
			{
				if($st['mtime'] < $this->baseTime)
				{
					/* The cache file exists, but is older than the last release
					 * of the application - force a re-fetch
					*/					 
				}
				else if($st['mtime'] > $cacheTime)
				{
					/* File was modified more recently than the post was updated */
					$this->log($cacheKey . ' Using cached version of ' . $sourceUrl);
					$buf = @file_get_contents($cachePath);
					$loadedFromCache = true;
				}
			}
		}
		if($buf === null)
		{
			$this->log('Fetching ' . $sourceUrl);
			$buf = @file_get_contents($sourceUrl);
		}
		if(!$loadedFromCache)
		{
			/* Update the cache regardless of whether we're using it in this
			 * round to speed updates
			 */
			file_put_contents($cachePath, $buf);
		}
		if(!strlen($buf))
		{
			return;
		}
		$headers = array(
			'Status' => '200 OK',
			'Content-Type' => 'text/html',
			'Content-Location' => $this->contentPath($sourceUrl, $defaultExt),
		);
		return $this->writeContentForLink($sourceUrl, $buf, $headers, $defaultExt);
	}

	protected function getHostname()
	{
		$hostname = php_uname('n');
		return $hostname;
	}
		
	protected function recursiveRemove($path)
	{
		@exec('rm -rf ' . escapeshellarg($path) . ' 2>/dev/null');
	}
	
	/* Create all of the symbolic links back into the CMS tree */
	protected function buildSymlinks()
	{
		$abs = ABSPATH;
		if(substr($abs, -1) != '/')
		{
			$abs .= '/';
		}
		$content = WP_CONTENT_DIR;
		if(strncmp($content, $abs, strlen($abs)))
		{
			/* What? */
			return;
		}
		
		$rel = substr($content, strlen($abs));
		@unlink(STATICGEN_PATH . '/' . $this->subdir . '/' . $rel);
		symlink(realpath($content), STATICGEN_PATH . '/' . $this->subdir . '/' . $rel);
		$info = wp_upload_dir();
		$dirs = array($info['basedir']);
		foreach($dirs as $d)
		{
			if(!strncmp($d, $content . '/', strlen($content) + 1))
			{
				/* It's inside WP_CONTENT_DIR */
				continue;
			}
			if(strncmp($d, $abs, strlen($abs)))
			{
				/* ...it's outside both WP_CONTENT_DIR and ABSPATH */
				continue;
			}
			$rel = substr($d, strlen($abs));
			@unlink(STATICGEN_PATH . '/' . $this->subdir . '/' . $rel);
			symlink(realpath($d), STATICGEN_PATH . '/' . $this->subdir . '/' . $rel);
		}
	}

	/* Update the static version of a post */
	protected function updatePost($info, $useCache = false)
	{
		$subsidiaries = array();
		
		$permalink = get_permalink(is_object($info) ? $info->ID : $info);
		if(!is_object($info))
		{
			$info = get_post($info);
		}
		if($info->post_status != 'publish')
		{
			return;
		}
		$custom = get_post_custom($info->ID);
		$info->object_type = 'post';		
		$fallback = array('text/html' => $permalink);
		$subsidiaries = apply_filters('static_subsidiaries', $subsidiaries, $info);
		if(!is_array($subsidiaries))
		{
			$subsidiaries = array();
		}
		$subsidiaries = apply_filters('static_subsidiaries_' . $info->post_type, $subsidiaries, $info);
		if(!is_array($subsidiaries))
		{
			$subsidiaries = array();
		}
		$subs = implode(', ', $subsidiaries);
		if(strlen($subs))
		{
			$subs = ' (' . $subs . ')';
		}
		$this->log('Updating object and subsidiaries' . $subs, ' [#' . $info->ID . '/' . $info->post_type . '/' . $info->post_name . '] (modified ' . $info->post_modified_gmt . ')');
		$this->updateObject($info, $custom, $permalink, $fallback, $useCache);
		foreach($subsidiaries as $sub)
		{
			$info->parent_type = 'post';
			$info->subsidiary_type = $sub;
			$info->object_type = 'post_' . $sub;
			
			$fallback = array('text/html' => $permalink . '/' . $sub);
			$this->updateObject($info, $custom, $permalink . '/' . $sub, $fallback, $useCache);
		}
	}
	
	/* Update the static archive for a year */
	protected function updateYearArchive($year, $root = null)
	{
		global $wpdb;

		if(!strlen($root))
		{
			$root = $this->siteUrl();
			if(substr($root, -1) != '/')
			{
				$root .= '/';
			}
		}
		$this->fetchAndStore($root . $year);
		for($month = 1; $month <= 12; $month++)
		{			
			$a = sprintf('%04d-%02d-01 00:00:00', $year, $month);
			$b = sprintf('%04d-%02d-01 00:00:00', ($month == 12 ? $year + 1 : $year), ($month == 12 ? 1 : $month + 1));
			$id = $wpdb->get_var($wpdb->prepare('SELECT `ID` FROM ' . $wpdb->posts . ' WHERE `post_date` >= %s AND `post_date` < %s AND `post_status` = %s', $a, $b, 'publish'));
			if(strlen($id))
			{
				$this->updateMonthArchive($year, $month, $root);
			}
		}
	}
	
	/* Update the static version of a monthly archive */
	protected function updateMonthArchive($year, $month, $root)
	{
		$this->fetchAndStore(sprintf($root . '%04d/%02d', $year, $month));
	}

	/* Update the static version of an author page */
	protected function updateUser($user, $root = null)
	{
		global $wpdb;

		if(!strlen($root))
		{
			$root = $this->siteUrl();
			if(substr($root, -1) != '/')
			{
				$root .= '/';
			}
		}
		if(!is_object($user))
		{
			$user = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->users . ' WHERE `ID` = %d', $user));
		}
		if(is_object($user))
		{
			$this->fetchAndStore($root . 'authors/' . $user->user_login);
		}
	}
}

$staticgen = new StaticGen();


