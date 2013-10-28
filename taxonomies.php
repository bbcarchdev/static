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

/* Write all of the taxonomies as part of the staticgen build process */

class StaticGenTaxonomies extends StaticGenHandler
{
	public /*callback*/ function staticgen_rebuild_phase($instance)
	{
		$taxonomies = get_taxonomies();
		$root = $instance->siteUrl();
		if(substr($root, -1) != '/')
		{
			$root .= '/';
		}
		foreach($taxonomies as $tax)
		{
			$instance->log('Building ' . $tax);
			$obj = get_taxonomy($tax);
			if(empty($obj->public) || !isset($obj->rewrite) || !strlen($obj->rewrite['slug']))
			{
				continue;
			}
			$base = $root . $obj->rewrite['slug'];
			$obj->object_type = 'taxonomy';
			$fallback = array('text/html' => $base);
			$instance->updateObject($obj, null, $base, $fallback);
			$terms = get_terms($obj->name);
			foreach($terms as $k => $v)
			{
				$link = get_term_link($v);
				$v->object_type = 'term';
				$v->taxonomy_object = $obj;
				$v->link = $link;
				$fallback = array('text/html' => $link);
				$instance->updateObject($v, null, $link, $fallback);
			}
		}
	}
}
