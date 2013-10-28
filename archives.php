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

/* Write the site archives as part of the staticgen build process */

class StaticGenArchives extends StaticGenHandler
{
	public /*callback*/ function staticgen_rebuild_phase($instance)
	{
		global $wpdb;

		$earliest = $wpdb->get_var($wpdb->prepare('SELECT MIN(`post_date`) FROM ' . $wpdb->posts . ' WHERE `post_status` = %s', 'publish'));
		$year = intval(substr($earliest, 0, 4));
		$thisyear = intval(strftime('%Y'));
		for($y = $year; $y <= $thisyear; $y++)
		{
			$this->updateYearArchive($instance, $y);
		}
	}
	
	/* Update the static archive for a year */
	protected function updateYearArchive($instance, $year, $root = null)
	{
		global $wpdb;

		if(!strlen($root))
		{
			$root = $instance->siteUrl();
			if(substr($root, -1) != '/')
			{
				$root .= '/';
			}
		}
		$instance->fetchAndStore(get_year_link($year));
		for($month = 1; $month <= 12; $month++)
		{			
			$a = sprintf('%04d-%02d-01 00:00:00', $year, $month);
			$b = sprintf('%04d-%02d-01 00:00:00', ($month == 12 ? $year + 1 : $year), ($month == 12 ? 1 : $month + 1));
			$id = $wpdb->get_var($wpdb->prepare('SELECT `ID` FROM ' . $wpdb->posts . ' WHERE `post_date` >= %s AND `post_date` < %s AND `post_status` = %s', $a, $b, 'publish'));
			if(strlen($id))
			{
				$this->updateMonthArchive($instance, $year, $month, $root);
			}
		}
	}
	
	/* Update the static version of a monthly archive */
	protected function updateMonthArchive($instance, $year, $month, $root)
	{
		$instance->fetchAndStore(get_month_link($year, $month));
	}	
}

