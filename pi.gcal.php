<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once('simplepie.inc');

$plugin_info = array(
						'pi_name'			=> 'Google Calendar',
						'pi_version'		=> '0.5a',
						'pi_author'			=> 'Jason Hamilton-Mascioli (Orig Matthew Romaine)',
						'pi_author_url'		=> 'http://www.kickstartlabs.com',
						'pi_description'	=> 'Allows you to display information from a Google Calendar feed (Converted to EE2)',
						'pi_usage'			=> GCal::usage()
					);
					
/**
 * GCal Class
 *
 * @package			ExpressionEngine
 * @category		Plugin
 * @author			Matthew Romaine
 * @link			http://expressionengine.com/downloads/details/google_calendar/
 */
class GCal {
	
	var $return_data;
	var $gcal_id         = '';
	var $cache_name		= 'gcal_cache';
	var $cache_expired	= FALSE;
	var $refresh		= 30;		// Period between cache refreshes, in minutes
	var $limit			= 10;
    var $show_future   = 'false';  // yes, as a string
    var $orderby        = 'starttime';
    var $sort_order      = 'ascending';
    var $startmin       = '';
    var $startmax       = '';
	var $events         = array();
	
	/**
	 * Constructor
	 *
	 */
	function GCal()
	{
	  // depricated
		//global $TMPL;
		
		$this->EE =& get_instance();

		/** ---------------------------------------
		/**  Fetch parameters
		/** ---------------------------------------*/
				
		$this->gcal_id = (($gcal_id = $this->EE->TMPL->fetch_param('gcal_id')) === FALSE) ? $this->gcal_id: html_entity_decode($gcal_id);
		$this->refresh = (($refresh = $this->EE->TMPL->fetch_param('refresh')) === FALSE) ? $this->refresh : $refresh;
		$this->limit = (($limit = $this->EE->TMPL->fetch_param('limit')) === FALSE) ? $this->limit : $limit;
		$this->show_future = (($show_future = $this->EE->TMPL->fetch_param('show_future')) === FALSE) ? $this->show_future : $show_future;
		$this->orderby = (($orderby = $this->EE->TMPL->fetch_param('orderby')) === FALSE) ? $this->orderby : $orderby;
		$this->sort_order = (($sort_order = $this->EE->TMPL->fetch_param('sort_order')) === FALSE) ? $this->sort_order : $sort_order;
		$this->startmin = (($startmin = $this->EE->TMPL->fetch_param('from')) === FALSE) ? $this->startmin : $startmin;
		$this->startmax = (($startmax  = $this->EE->TMPL->fetch_param('to')) === FALSE) ? $this->startmax : $startmax;
    
      
      
		if (strlen($this->gcal_id) < 1)
		{
			$this->EE->TMPL->log_item("GCal error: Invalid URL");
			$this->return_data = '';
			return;
		}
		
		/** ---------------------------------------
		/**  Create the full url
		/** ---------------------------------------*/
		$url = 'http://www.google.com/calendar/feeds/' . $this->gcal_id . '/public/full?orderby=' . $this->orderby;
        $url .= '&sortorder=' . $this->sort_order . '&futureevents=' . $this->show_future;
        
        
        if ($this->startmin != '') {
            $this->startmin = urlencode(date('c', strtotime($this->startmin)));
            $url .= '&start-min=' . $this->startmin;
        }

        if ($this->startmax != '') {
            $this->startmax = urlencode(date('c', strtotime($this->startmax)));
            $url .= '&start-max=' . $this->startmax;
        }

		/** ---------------------------------------
		/**  Fetch the XML from Google
		/** ---------------------------------------*/

		if (($rawxml = $this->_check_cache($url)) === FALSE)
		{
			$this->cache_expired = TRUE;
			$this->EE->TMPL->log_item("Fetching Google Calendar remotely");
			
	    	if ( function_exists('curl_init'))
	    	{
	    		$rawxml = $this->_curl_fetch($url); 
			}
			else
			{
				$rawxml = $this->_fsockopen_fetch($url);
	    	}		
		}
		
		if ($rawxml == '' OR substr($rawxml, 0, 5) != "<?xml")
		{
			$this->EE->TMPL->log_item("GCal error: Unable to retreive feed from Google => " . $rawxml);
			$this->return_data = '';
			return;
		}
		
		/** ---------------------------------------
		/**  Write cache
		/** ---------------------------------------*/
		
		if ($this->cache_expired == TRUE)
		{
			$this->_write_cache($rawxml, $url);			
		}
		
		/** ---------------------------------------
		/**  Parse the XML with SimplePie
		/** ---------------------------------------*/
		
	    if ( ! class_exists('SimplePie'))
	    {
			$this->EE->TMPL->log_item("GCal error: this plugin requires SimplePie v1.0 or greater");
			$this->return_data = '';
			return;
	    }

	    $feed = new SimplePie();
		//$cache_dir = APP_CACHE.$this->cache_name.'/';
		$cache_dir = APPPATH.'cache/'.$this->cache_name.'/';
		
        $feed->set_cache_location($cache_dir);
        $feed->set_stupidly_fast(TRUE);     // skips some sanity checks; may not be a good idea ... ?
        $feed->set_feed_url($url);
        $feed->init();

		$this->_load_events($feed);

		/** ---------------------------------------
		/**  Go go go!
		/** ---------------------------------------*/
		
		$this->return_data = $this->_parse_events();
	}

	// --------------------------------------------------------------------

	/**
	 * Parse Events
	 *
	 * Parses the Google Calendar
	 *
	 * @access	public
	 * @return	string
	 */
	function _parse_events()
	{
		//global $FNS, $LOC, $PREFS, $SESS, $TMPL;
		//global $LOC;
		
		$output = '';
		$starttime = array();
		$endtime = array();
		
		/** ---------------------------------------
		/**  Parse start_/end_time date variables 
        /**  outside of loop to save processing
		/** ---------------------------------------*/
		if (preg_match_all("/".LD.'start_time'."\s+format=(\042|\047)([^\\1]*?)\\1".RD."/s", $this->EE->TMPL->tagdata, $matches))
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{
				$matches['0'][$i] = str_replace(array(LD,RD), '', $matches['0'][$i]);
				$starttime[$matches['0'][$i]] = $this->EE->localize->fetch_date_params($matches['2'][$i]);
			}
		}

		if (preg_match_all("/".LD.'end_time'."\s+format=(\042|\047)([^\\1]*?)\\1".RD."/s", $this->EE->TMPL->tagdata, $matches))
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{
				$matches['0'][$i] = str_replace(array(LD,RD), '', $matches['0'][$i]);
				$endtime[$matches['0'][$i]] = $this->EE->localize->fetch_date_params->fetch_date_params($matches['2'][$i]);
			}
		}

		$count = 0;
		foreach($this->events as $key => $event)
		{
			$tagdata = $this->EE->TMPL->tagdata;
			$count++;

			if ($count > $this->limit)
			{
				return $output;
			}
			
			// allows {count} variable to be parsed
			$event['count'] = $count;
			
			/** ---------------------------------------
			/**  Prep conditionals
			/** ---------------------------------------*/
			
			$cond	 = $event;
			$tagdata = $this->EE->functions->prep_conditionals($tagdata, $cond);
			
			foreach($this->EE->TMPL->var_single as $var_key => $var_val)
			{
				/** ----------------------------------------
				/**  parse {switch} variable
				/** ----------------------------------------*/
				
				if (preg_match("/^switch\s*=.+/i", $var_key))
				{
					$sparam = $this->EE->functions->assign_parameters($var_key);
					
					$sw = '';
					
					if (isset($sparam['switch']))
					{
						$sopt = explode("|", $sparam['switch']);

						$sw = $sopt[($count-1 + count($sopt)) % count($sopt)];
					}
					
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $sw, $tagdata);
				}
				
				/** ---------------------------------------
				/**  parse {start_time}
				/** ---------------------------------------*/
								
				if (isset($starttime[$var_key]))
				{
                    $date = $event['start_time'];
					foreach ($starttime[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $this->EE->localize->convert_timestamp($dvar, $date, TRUE), $var_val);
					}
					
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}

				/** ---------------------------------------
				/**  parse {end_time}
				/** ---------------------------------------*/

				if (isset($endtime[$var_key]))
				{
                    $date = $event['end_time'];
					foreach ($endtime[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $this->EE->LOC->convert_timestamp($dvar, $date, TRUE), $var_val);
					}
					
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}
				
				// is the variable a primary key of the status?
				if (isset($event[$var_key]))
				{
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $event[$var_key], $tagdata);	
				}
				else
				{
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, '', $tagdata);
				}
			}
			
			$output .= $tagdata;
		}
		
		return $output;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Load Events
	 *
	 * Load Google Calendar events from SimplePie object
	 *
	 * @access	public
	 * @param	SimplePie object
	 * @return	void
	 */
    function _load_events($feed)
	{
		foreach($feed->get_items() as $event)
		{
            // grab Google-namespaced tags (<gd:when>, <gd:where>, etc.)
            $when = $event->get_item_tags('http://schemas.google.com/g/2005', 'when'); 
            $where = $event->get_item_tags('http://schemas.google.com/g/2005', 'where'); 

            $location = $where[0]['attribs']['']['valueString'];
            $startTime = $when[0]['attribs']['']['startTime'];
            $startTime = SimplePie_Misc::parse_date($startTime);
            $endTime = $when[0]['attribs']['']['endTime'];
            $endTime = SimplePie_Misc::parse_date($endTime);
            
            // provide event only if there's actually a title here (private events don't have titles) 
            if (strlen(trim($event->get_title())) > 1 ) { 
                $this->events[] = array(  
                                    'title' => $event->get_title(),
                                    'description' => $event->get_description(),
                                    'link' => $event->get_link(),
                                    'start_time' => $startTime, 
                                    'end_time' => $endTime,
                                    'location' => $location
                                 ); 
            } 
		}
	}

	// --------------------------------------------------------------------
	
	/**
	 * Check Cache
	 *
	 * Check for cached data
	 *
	 * @access	public
	 * @param	string
	 * @return	mixed - string if pulling from cache, FALSE if not
	 */

	function _check_cache($url)
	{	
		// Check for cache directory
		
		$dir = APPPATH.'cache/'.$this->cache_name.'/';
		
		if ( ! @is_dir($dir))
		{
			return FALSE;
		}
		
		// Check for cache file
		
       $file = $dir.md5($url);
		
		if ( ! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
		{
			return FALSE;
		}
		       
		flock($fp, LOCK_SH);
                   
		$cache = @fread($fp, filesize($file));
                   
		flock($fp, LOCK_UN);
       
		fclose($fp);

       // Grab the timestamp from the first line

		$eol = strpos($cache, "\n");
		
		$timestamp = substr($cache, 0, $eol);
		$cache = trim((substr($cache, $eol)));
		
		if ( time() > ($timestamp + ($this->refresh * 60)) )
		{
			$this->cache_expired = TRUE;
		}
		
       return $cache;
	}


	function _check_cache_depricated($url)
	{	
		//global $TMPL;
			
		/** ---------------------------------------
		/**  Check for cache directory
		/** ---------------------------------------*/
		
		$dir = PATH_CACHE.$this->cache_name.'/';
		
		if ( ! @is_dir($dir))
		{
			return FALSE;
		}

        $file = $dir.md5($url);
		
		if ( ! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
		{
			return FALSE;
		}
		       
		flock($fp, LOCK_SH);
                    
		$cache = @fread($fp, filesize($file));
                    
		flock($fp, LOCK_UN);
        
		fclose($fp);
        
		$eol = strpos($cache, "\n");
		
		$timestamp = substr($cache, 0, $eol);
		$cache = trim((substr($cache, $eol)));
		
		if (time() > ($timestamp + ($this->refresh * 60)))
		{
			return FALSE;
		}
		
		$this->EE->TMPL->log_item("Google Calendar retreived from cache");
		
        return $cache;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Write Cache
	 *
	 * Write the cached data
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */
	 
	 function _write_cache($data, $url)
 	{
 		// Check for cache directory

 		$dir = APPPATH.'cache/'.$this->cache_name.'/';

 		if ( ! @is_dir($dir))
 		{
 			if ( ! @mkdir($dir, 0777))
 			{
 				return FALSE;
 			}

 			@chmod($dir, 0777);            
 		}

 		// add a timestamp to the top of the file
 		$data = time()."\n".$data;


 		// Write the cached data

 		$file = $dir.md5($url);

 		if ( ! $fp = @fopen($file, 'wb'))
 		{
 			return FALSE;
 		}

 		flock($fp, LOCK_EX);
 		fwrite($fp, $data);
 		flock($fp, LOCK_UN);
 		fclose($fp);

 		@chmod($file, 0777);
 	}

 	
 	
	function _write_cache_depricated($data, $url)
	{
		/** ---------------------------------------
		/**  Check for cache directory
		/** ---------------------------------------*/
		
		$dir = PATH_CACHE.$this->cache_name.'/';

		if ( ! @is_dir($dir))
		{
			if ( ! @mkdir($dir, 0777))
			{
				return FALSE;
			}
			
			@chmod($dir, 0777);            
		}
		
		// add a timestamp to the top of the file
		$data = time()."\n".$data;
		
		/** ---------------------------------------
		/**  Write the cached data
		/** ---------------------------------------*/
		
		$file = $dir.md5($url);
	
		if ( ! $fp = @fopen($file, 'wb'))
		{
			return FALSE;
		}

		flock($fp, LOCK_EX);
		fwrite($fp, $data);
		flock($fp, LOCK_UN);
		fclose($fp);
        
		@chmod($file, 0777);		
	}

	// --------------------------------------------------------------------
	
	/**
	 * curl Fetch
	 *
	 * Fetch Twitter statuses using cURL
	 *
	 * @access	public
	 * @param	string
	 * @return	string
	 */
	function _curl_fetch($url)
	{
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

		$data = curl_exec($ch);
		
		curl_close($ch);

		return $data;
	}

	// --------------------------------------------------------------------
	
	/**
	 * fsockopen Fetch
	 *
	 * Fetch Twitter statuses using fsockopen
	 *
	 * @access	public
	 * @param	string
	 * @return	string
	 */
	function _fsockopen_fetch($url)
	{
		$target = parse_url($url);

		$data = '';

		$fp = fsockopen($target['host'], 80, $error_num, $error_str, 8); 

		if (is_resource($fp))
		{
			fputs($fp, "GET {$url} HTTP/1.0\r\n");
			fputs($fp, "Host: {$target['host']}\r\n");
			fputs($fp, "Authorization: Basic ".base64_encode("$this->user:$this->password")."\r\n");
			fputs($fp, "User-Agent: EE/EllisLab PHP/" . phpversion() . "\r\n\r\n");

		    $headers = TRUE;

		    while( ! feof($fp))
		    {
		        $line = fgets($fp, 4096);

		        if ($headers === FALSE)
		        {
		            $data .= $line;
		        }
		        elseif (trim($line) == '')
		        {
		            $headers = FALSE;
		        }
		    }

		    fclose($fp); 
		}
		
		return $data;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Usage
	 *
	 * Plugin Usage
	 *
	 * @access	public
	 * @return	string
	 */
	function usage()
	{
		ob_start(); 
		?>
		------------------
		EXAMPLE USAGE:
		------------------
		
		{exp:gcal gcal_id="bn44qj4p2l2usv6avn45v7lb28%40group.calendar.google.com" from="June 6, 2006" show_future="true" orderby="d" limit="5"}
        <ul>
            <li> 
                {if description}<a href="{description}">{title}</a>{if:else}{title}{/if} <br /> 
                from {start_time format="%Y-%m-%d %H:%i"} to {end_time format="%Y-%m-%d %H:%i"} <br/> 
                location: {location} <br/> 
                <a href="{link}">add to your calendar</a> 
            </li> 
        </ul>
		{/exp:gcal}
		
		------------------
		PARAMETERS:
		------------------
		
		gcal_id="bn44qj4p2l2usv6avn45v7lb28%40group.calendar.google.com"
		- Google Calendar id. Must be for a publicly available calendar.
		
		from/to="July 1, 2008"
		- start and end dates to filter events. any valid input to strtotime is fine. 
		
		show_future="true"
		- whether to grab events into the future. default is false. if parameter "to" is
          given and is in the future, show_future will be overriden.
		
		limit="5"
		- Number of events to limit to.  Default is 10.
		
		refresh="20"
		- Time (in minutes) of cache interval for the requested calendar.  Defaults to 30.
				
		------------------
		VARIABLES:
		------------------
		
		{count}
		{switch="one|two|three"}
		{start_time format="%m-%d-%Y"}
		{end_time format="%m-%d-%Y"}
		{title}
		{description}
		{location}
		{link}
		
		------------------
		TROUBLESHOOTING:
		------------------
		
        This plugin requires SimplePie (http://simplepie.org/).  Make sure the file "simplepie.inc" is also in your plugin directory.

		All error messages are logged in the Template Parsing Log.  If you have no output, or unexpected output, enable the Template Parsing Log in your Output and Debugging Preferences.
		

        ------------------
        NOTES
        ------------------

        Refer to the Google Calendar API http://code.google.com/apis/calendar/reference.html and Google Data API http://code.google.com/apis/gdata/reference.html for more parameter options and possible extensions.
		
		<?php
		$buffer = ob_get_contents();

		ob_end_clean(); 

		return $buffer;
	}

	// --------------------------------------------------------------------
	
}
// END GCal Class
/* End of file  pi.gcal.php */
/* Location: ./system/expressionengine/third_party/gcal/pi.gcal.php */