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

/* Write the user archives as part of the staticgen build process */

class StaticGenUsers extends StaticGenHandler
{
	public /*callback*/ function staticgen_rebuild_phase($instance)
	{
		global $wpdb;

		$users = $wpdb->get_col('SELECT DISTINCT `post_author` FROM ' . $wpdb->posts);
		foreach($users as $id)
		{
			$url = get_author_posts_url($id);
			if(strlen($url))
			{
				$instance->fetchAndStore($url);
			}
		}
	}
}

