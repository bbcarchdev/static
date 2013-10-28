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

/* Write the site feeds as part of the staticgen build process */

class StaticGenFeeds extends StaticGenHandler
{
	public /*callback*/ function staticgen_rebuild_phase($instance)
	{
		$feeds = array(
			'/feed' => array('.rss', 'application/rss+xml'),
			'/feed/rss' => array('.rss', 'application/rss+xml'),
			'/feed/rss2' => array('.rss', 'application/rss+xml'),
			'/feed/atom' => array('.atom', 'application/atom+xml'),
			'/feed/rdf' => array('.rdf', 'application/rdf+xml'),
			);
		foreach($feeds as $uri => $info)
		{
			$instance->fetchAndStore($uri, $info[0], null, $info[1]);
		}		
	}
}
