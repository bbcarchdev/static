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

/* Write all posts and pages as part of the staticgen build process */

class StaticGenPosts extends StaticGenHandler
{
	protected $priority = 200;
	
	public /*callback*/ function staticgen_rebuild_phase($instance)
	{
		global $wpdb;
		static $statusList = array('publish', 'future', 'private', 'pending');

		$instance->performAction('staticgen_rebuild_posts_begin', $instance);
		$permalink = get_option('permalink_structure');
		$permalink = str_replace('%postname%', '', $permalink);
		if(strpos($permalink, '%') === false)
		{		
			$object = (object) null;
			$object->object_type = 'posts';
			$object->link = $permalink;
			$instance->updateObject($object, null, $permalink);
		}
		foreach($statusList as $status)
		{
			$list = $wpdb->get_col($wpdb->prepare('SELECT `ID` FROM ' . $wpdb->posts . ' WHERE `post_status` = %s', $status));
			$instance->log('Updating', count($list), 'posts');
			$c = 0;
			foreach($list as $post)
			{
				$c++;		
				$instance->updatePost($post, true);
				if(!($c % 20))
				{
					$instance->log('Updated', $c, 'posts');
				}
			}
		}
		$instance->performAction('staticgen_rebuild_posts_complete', $instance);		
	}
}
