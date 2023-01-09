<?php
/*
 * YouTube Captions Auditor (YTCA)
 * version 1.1
 *
 */

/*******************
 *                 *
 *  CONFIGURATION  *
 *                 *
 *******************/

// See the YouTube API reference for instructions on obtaining an API key
// https://developers.google.com/youtube/v3/docs/
// Store API key in a local file
$apiKeyFile = 'apikey'; // name of local file in which API key is stored

// Debug (can be overwritten with parameter 'debug' in URL)
// Value is either 1, 2, or false (default)
// 1 = Adds additional data to output: Expected # of videos per channel & Google cost (in units)
// 2 = Same as 1, plus displays YouTube API query URLs in output so user can inspect raw data directly
// Legacy value of 'true' = 2
$settings['debug'] = false;

// Path to channels ini file (can be overwritten with parameter 'channels' in URL)
$settings['channelsFile'] = 'channels.ini';

// Output (can be overwritten with parameter 'output' in URL)
// Supported values: html, xml, json
$settings['output'] = 'html';

// Report (can be overwritten with parameter 'report' in URL)
// Supported values:
//  summary - counts and other stats for each channel
//  details - metadata, traffic data, and caption data for each video in results
$settings['report'] = 'summary';

// Filter Type (can be overwritten with parameter 'filtertype' in URL)
// Used in conjunction with Filter Value to filter videos based on views
// This can be used to prioritize accessibility efforts on videos that have the highest traffic
// Supported values:
//   views - limit results to videos that have X or more views
//   percentile - limit results to videos that fall into the X percentile for the channel based on views (e.g., the top 10%)
//   count - limit results to the top X videos for the channel, based on views
//   NULL - do not filter; include all videos in audit
$filterType = NULL;

// Filter Value (can be overwritten with parameter 'filtervalue' in URL)
// Used to define the value of Filter Type, as explained above
// Set to NULL if no filtering is used
$filterValue = NULL;

// Title of report (can be overwritten with URL-encoded parameter 'title' in URL)
$settings['title'] = 'YouTube Caption Auditor (YTCA) Report';

// Time unit for "Duration" data (can be overwritten with 'timeunit' in URL)
// Supported values are 'seconds' (default), 'minutes', or 'hours'
$settings['timeUnit'] = 'seconds';

// Include Channel ID
// Set to true to include a YouTube Channel ID column in the HTML output of summary report; otherwise false
$settings['showChannelId'] = false;

// Highlights
// Optionally, the report can highlight channels that are either doing good or bad at captioning
// To use this feature, set the following variables
// if $highlights['use'] is false, all other variables are ignored
$highlights['use'] = true;
$highlights['goodPct'] = 50; // Percentages >= this value are "good"
$highlights['badPct'] = 0; // Percentages <= this value are "bad"
$highlights['goodLabel'] = 'Exemplary channel'; // title attribute on channel name for 'good' channels
$highlights['badLabel'] = 'Needs work'; // title attribute on channel name for 'bad' channels

/***********************
 *                     *
 *  END CONFIGURATION  *
 *                     *
 ***********************/

error_reporting(E_ERROR | E_PARSE);
ini_set('max_execution_time',0); // in seconds; 0 = run until finished

// calculate time of execution
$timeStart = microtime(true);

$apiKey = file_get_contents($apiKeyFile);

// Override default variables with GET params
if (isset($_GET['debug'])) {
  // convert to boolean; accept 'true', 1 or 2
  if (strtolower($_GET['debug']) == 'true' || $_GET['debug'] == '2') {
    $settings['debug'] = 2;
  }
  elseif ($_GET['debug'] == '1') {
    $settings['debug'] = 1;
  }
}
if (isset($_GET['output'])) {
  if (isValid('output',strtolower($_GET['output']))) {
    $settings['output'] = strtolower($_GET['output']);
  }
}
if (isset($_GET['report'])) {
  if (isValid('report',strtolower($_GET['report']))) {
    $settings['report'] = strtolower($_GET['report']);
  }
}
$filter = false;
$sortBy = 'title'; // default, conditionally overridden
if (isset($_GET['date-start'])) {
  if (isValid('date',$_GET['date-start'])) {
    $filter['dateStart'] = $_GET['date-start'];
    $sortBy = 'date';
  }
}
if (isset($_GET['date-end'])) {
  if (isValid('date',$_GET['date-end'])) {
    $filter['dateEnd'] = $_GET['date-end'];
    $sortBy = 'date';
  }
}
if (isset($_GET['filtertype']) && isset($_GET['filtervalue'])) {
  if (isValid('filterType',strtolower($_GET['filtertype']))) {
    if (isValid('filterValue',$_GET['filtervalue'],strtolower($_GET['filtertype']))) {
      $filter['type'] = strtolower($_GET['filtertype']);
      $filter['value'] = $_GET['filtervalue'];
      $sortBy = 'viewCount'; // takes precedence over date filter
    }
  }
}
if (isset($_GET['title'])) {
  if (isValid('title',strip_tags($_GET['title']))) {
    $settings['title'] = urldecode(strip_tags($_GET['title']));
  }
}
if (isset($_GET['timeunit'])) {
  if (isValid('timeUnit',strtolower($_GET['timeunit']))) {
    $settings['timeUnit'] = strtolower($_GET['timeunit']);
  }
}
if (isset($_GET['channels'])) {
  if (isValid('channels',strip_tags($_GET['channels']))) {
    $settings['channelsFile'] = urldecode(strip_tags($_GET['channels']));
  }
}
if (isset($_GET['show-channel-id'])) {
  // convert to boolean; accept 'true', 'false' or 1 or 0
  if (strtolower($_GET['show-channel-id']) == 'true' || $_GET['show-channel-id'] == '1') {
    $settings['showChannelId'] = true;
  }
}

// set default vars; may be conditionally overridden
$footnotes = NULL;

showTop($settings,$highlights['goodColor'],$highlights['badColor'],$filter);

// Get channel from URL (channelid and (optionally) channelname)
// if either parameter is included in URL, that channel is audited rather than using channels.ini
if ($channelId = $_GET['channelid']) {
  if (!(ischannelId($channelId))) {
    // this is not a valid channel ID; must be a username
    $channelIdArray = getChannelId($apiKey,$channelId);
    $channelId = $channelIdArray['id']; // returns null if getChannelId() fails to return an id
    // initiate auditing vars
    $initChannelCost = $channelIdArray['cost'];
    $initChannelRequest = 1;
  }
  $channels[0]['id'] = $channelId;
  if (isset($_GET['channelname'])) {
    $channels[0]['name'] = $_GET['channelname'];
  }
  else { // get the channel name from YouTube
    $channelNameArray = getChannelName($apiKey,$channelId);
    $channels[0]['name'] = $channelNameArray['name'];
    // initiate auditing vars
    $initChannelCost = $channelNameArray['cost'];
    $initChannelRequests++;
  }
}
else {
  // get channel(s) from ini file
  $channels = parse_ini_file($settings['channelsFile'],true); // TODO: Handle syntax errors in .ini file
  // initiate auditing vars (no API requests required so far)
  $initChannelCost = 0;
  $initChannelRequests = 0;
}

if (is_array($channels)) {

  $numChannels = count($channels);

  if ($numChannels > 0) {

    // initialize $totals

    // all videos (count and duration)
    $totals['all']['count'] = 0;
    $totals['all']['duration'] = 0;
    $totals['all']['views'] = 0;
    $totals['all']['maxViews'] = 0;
    if ($settings['debug'] > 0) {
      $totals['all']['approxCount'] = 0;
      $totals['all']['requests'] = $initChannelRequests;
      $totals['all']['cost'] = $initChannelCost;
    }

    // captioned videos (count and duration)
    $totals['cc']['count'] = 0;
    $totals['cc']['duration'] = 0;

    if (!$filter) {
      // no reason to separate out high traffic videos if already filtering for high traffic

      // high traffic videos (count and duration)
      $totals['highTraffic']['count'] = 0;
      $totals['highTraffic']['duration'] = 0;

      // captioned high traffic videos (count and duration)
      $totals['ccHighTraffic']['count'] = 0;
      $totals['ccHighTraffic']['duration'] = 0;
    }

    $channelMeta = getChannelMeta($channels); // return an array of metadata for each channel, else false

    if (function_exists('array_key_first')) { // function introduced in PHP 7.3.0
      $k = array_key_first($channels);
    }
    else {
      $k = getFirstKey($channels);
    }

    if (function_exists('array_key_last')) { // function introduced in PHP 7.3.0
      $lastKey = array_key_last($channels);
    }
    else {
      $lastKey = getLastKey($channels);
    }

    $c = 0; // counter


    // prepare to write output immediately to screen (rather than wait for script to finish executing)
    if (ob_get_level() == 0) {
      ob_start();
    }

    if ($settings['output'] == 'html') {
      if ($settings['report'] == 'summary') {
        $firstChannelName = $channels[$k]['name'];
        $footnotes = showSummaryTableTop($settings,$numChannels,$firstChannelName,$channelMeta,$filter);
      }
    }
    elseif ($settings['output'] == 'xml') {
      echo '<channels>'."\n";
    }
    elseif ($settings['output'] == 'json') {
      echo '"channels": ['."\n";
    }

    // Step through each channel
    while ($k <= $lastKey) {

      $channelRequests = $initChannelRequests;
      $channelCost = $initChannelCost;

      if (!(ischannelId($channels[$k]['id']))) {
        // this is not a valid channel ID; must be a username
        // getChannelId() returns an array with 'id' and 'cost'
        $channelIdArray = getChannelId($apiKey,$channels[$k]['id']);
        $channels[$k]['id'] = $channelIdArray['id'];
        $channelCost += $channelIdArray['cost'];
        $channelRequests++;
      }
      $channelQueryArray = buildYouTubeQuery('search',$channels[$k]['id'],NULL,$apiKey,$sortBy);
      $channelQuery = $channelQueryArray['url'];
      $channelCost += $channelQueryArray['cost'];
      $channelRequests++;
      // create an array of metadata for this channel (if any exists)
      $numKeys = count($channels[$k]);
      if ($numKeys > 2) {
        // there is supplemental meta data in the array
        $keys = array_keys($channels[$k]);
        $i = 0;
        while ($i < $numKeys) {
          $key = $keys[$i];
          if ($key !== 'name' && $key !== 'id') {
            $metaKeys[] = $key;
          }
          $i++;
        }
      }
      if ($settings['debug'] == 2 && $settings['output'] == 'html') {
        echo '<div class="ytca_debug ytca_channel_query">';
        echo '<span class="query_label">Initial channel query:</span>'."\n";
        echo '<span class="query_url"><a href="'.$channelQuery.'">'.$channelQuery."</a></span>\n";
        echo "</div>\n";
      }
      if ($content = fileGetContents($channelQuery)) {
        $json = json_decode($content,true);
        if ($channels[$k]['id'] == $channels[$k]['name']) {
          // id and name are the same - reset with channel name from search results
          $channels[$k]['name'] = $json['items'][0]['snippet']['channelTitle'];
        }
        $approxNumVideos = $json['pageInfo']['totalResults'];
        $channel['videoCount'] = $numVideos;
        if ($approxNumVideos > 0) {
          // add a 'videos' key for this channel that point to all videos
          $videosArray = getVideos($settings,$channels[$k]['id'],$json,$approxNumVideos,$apiKey,$sortBy);
          $channels[$k]['videos'] = $videosArray;
          $channelCost += $videosArray['cost'];
          $channelRequests += $videosArray['requests'];
        }
        else {
          // TODO: handle error: No videos returned by $channelQuery
        }
      }
      else {
        // TODO: handle error: Unable to retrieve file: $channelQuery
      }
      if ($filter) {
        $videos = applyFilter($channels[$k]['videos'],$filter);
      }
      else {
        $videos = $channels[$k]['videos'];
      }
      // $numVideos is the *actual* number of videos returned
      // Note that it includes 2 additional keys ('requests' and 'costs') that must be removed from the total
      $numVideos = count($videos) - 2;
      // add values to channel totals
      if ($settings['debug'] > 0) {
        $channelData['all']['approxCount'] = $approxNumVideos;
        $channelData['all']['requests'] = $channelRequests;
        $channelData['all']['cost'] = $channelCost;
      }
      $channelData['all']['count'] = $numVideos;
      $channelData['all']['duration'] = calcDuration($videos,$numVideos);
      $viewsData = countViews($videos,$numVideos); // returns array with keys 'count' and 'max'
      $channelData['all']['views'] = $viewsData['count'];
      $channelData['all']['maxViews'] = $viewsData['max'];
      $channelData['all']['avgViews'] = round($channelData['all']['views']/$channelData['all']['count']);
      $channelData['cc']['count'] = countCaptioned($videos,$numVideos);
      $channelData['cc']['duration'] = calcDuration($videos,$numVideos,'true');
      if (!$filter) {
        // no reason to separate out high traffic videos if already filtering for high traffic
        $highTrafficThreshold = $channelData['all']['avgViews'];
        $channelData['highTraffic']['count'] = countHighTraffic($videos,$numVideos,$highTrafficThreshold);
        $channelData['highTraffic']['duration'] = calcDuration($videos,$numVideos,NULL,$highTrafficThreshold);
        $channelData['ccHighTraffic']['count'] = countCaptioned($videos,$numVideos,$highTrafficThreshold);
        $channelData['ccHighTraffic']['duration'] = calcDuration($videos,$numVideos,'true',$highTrafficThreshold);
      }
      $rowNum = $c + 1;
      if ($rowNum < $numChannels) {
        $nextChannelName = $channels[$rowNum]['name'];
      }

      if ($settings['report'] == 'details') {
        // show details for this channel
        showDetails($settings,$rowNum,$numChannels,$channels[$k],$channelMeta[$k],$channelData,$videos,$numVideos,$filter,$sortBy);
      }
      else { // show a summary report
        showSummaryTableRow($settings,$rowNum,$numChannels,$channels[$k],$nextChannelName,$channelMeta[$k],$channelData,$filter,$highlights);

        // increment totals with values from this channel
        if ($settings['debug'] > 0) {
          $totals['all']['approxCount'] += $channelData['all']['approxCount'];
          $totals['all']['requests'] += $channelData['all']['requests'];
          $totals['all']['cost'] += $channelData['all']['cost'];
        }
        $totals['all']['count'] += $channelData['all']['count'];
        $totals['all']['duration'] +=  $channelData['all']['duration'];
        $totals['all']['views'] +=  $channelData['all']['views'];
        if ($channelData['all']['maxViews'] > $totals['all']['maxViews']) {
          $totals['all']['maxViews'] = $channelData['all']['maxViews'];
        }
        $totals['cc']['count'] += $channelData['cc']['count'];
        $totals['cc']['duration'] += $channelData['cc']['duration'];
        if (!$filter) {
          // no reason to separate out high traffic videos if already filtering for high traffic
          $totals['highTraffic']['count'] += $channelData['highTraffic']['count'];
          $totals['highTraffic']['duration'] += $channelData['highTraffic']['duration'];
          $totals['ccHighTraffic']['count'] += $channelData['ccHighTraffic']['count'];
          $totals['ccHighTraffic']['duration'] += $channelData['ccHighTraffic']['duration'];
        }
      }
      $c++;
      $k++;
    }

    if ($settings['report'] == 'summary') {
      // add totals row
      showSummaryTableRow($settings,'totals',$numChannels,NULL,NULL,$channelMeta[0],$totals,$filter);
      showSummaryTableBottom($settings['output'],$footnotes);
    }
  } // end if $numChannels > 0
  else {
    // TODO: handle error - no channels were found
  }
} // end if $channels in an array

showBottom($settings['output']);

// stop calculating time of execution and display results
$timeEnd = microtime(true);
$time = round($timeEnd - $timeStart,2); // in seconds
if ($settings['output'] == 'html' && $settings['debug'] == 2) {
  echo '<p class="runTime">Total run time: '.makeTimeReadable($time).'</p>'."\n";
}

ob_end_flush();

function showTop($settings,$goodColor,$badColor,$filter=NULL) {

  if ($settings['output'] == 'html') {
    echo "<!DOCTYPE html>\n";
    echo '<html lang="en">'."\n";
    echo "<head>\n";
    echo '<meta charset="utf-8">'."\n";
    echo '<title>'.$settings['title']."</title>\n";
    echo '<link rel="stylesheet" type="text/css" href="styles/ytca.css">'."\n";
    echo "</head>\n";
    echo '<body id="ytca">'."\n";
    echo '<h1>'.$settings['title']."</h1>\n";
    echo '<p class="date">'.date('M d, Y')."</p>\n";
    echo '<div id="status" role="alert"></div>'."\n";
    echo '<script src="//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>'."\n";
    echo '<script src="scripts/ytca.js"></script>'."\n";
    echo '<script src="scripts/tablesort.js"></script>'."\n";
    if ($filter) {
      echo '<p class="filterSettings">';
      echo 'Filter on. Including only ';
      $needAnd = false;
      if ($filter['type'] == 'views') {
        echo 'videos with <span class="filterValue">'.$filter['value'].'</span> views';
        $needAnd = true;
      }
      elseif ($filter['type'] == 'percentile') {
        echo 'videos in the <span class="filterValue">';
        echo $filter['value'].getOrdinalSuffix($filter['value']);
        echo '</span> percentile for each channel';
        $needAnd = true;
      }
      elseif ($filter['type'] == 'count') {
        echo 'the top <span class="filterValue">'.$filter['value'].'</span> videos in each channel ';
        echo '(based on views)';
        $needAnd = true;
      }
      if ($filter['dateStart'] || $filter['dateEnd']) {
        if ($needAnd) {
          echo ' and published ';
        }
        else {
          echo 'videos published ';
        }
        if ($filter['dateStart'] && $filter['dateEnd']) {
          echo 'between ';
          echo '<span class="filterValue">'.$filter['dateStart'].'</span> and ';
          echo '<span class="filterValue">'.$filter['dateEnd'].'</span>';
        }
        elseif ($filter['dateStart']) {
          echo 'on or after ';
          echo '<span class="filterValue">'.$filter['dateStart'].'</span>';
        }
        elseif ($filter['dateEnd']) {
          echo 'on or before ';
          echo '<span class="filterValue">'.$filter['dateEnd'].'</span>';
        }
      }
      echo ".</p>\n";
    }
  }
  elseif ($settings['output'] == 'xml') {
    header("Content-type: text/xml");
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    echo '<ytca>'."\n";
    addMetaTags('xml',$settings,$filter);
  }
  elseif ($settings['output'] == 'json') {
    header('Content-Type: application/json');
    echo '{'."\n";
    echo '"ytca": {'."\n";
    addMetaTags('json',$settings,$filter);
  }
}

function showSummaryTableTop($settings,$numChannels,$firstChannelName,$channelMeta,$filter) {

  // $metaData is an array of 'keys' and 'values' for each channel; or false
  // returns an array $footnotes

  $numFootnotes = 0;

  if ($settings['output'] == 'html') {
    echo '<table id="report" class="summary">'."\n";
    echo '<thead>'."\n";
    echo '<tr';
    // add a data-status attribute that's used by ytca.js to populate the status message at the top of the page
    // this reflects the *next* channel, since it isn't written to the screen until the channel row is complete
    if ($firstChannelName) {
      echo ' data-status="Processing Channel 1 of '.$numChannels.': '.$firstChannelName.'..."';
    }
    echo '>'."\n";
    echo '<th scope="col"><span>YouTube Channel</span></th>'."\n";
    if ($settings['showChannelId']) {
      echo '<th scope="col"><span>YouTube ID</span></th>'."\n";
    }
    if ($channelMeta) {
      $metaKeys = array_keys($channelMeta[0]); // get keys from first channel in array
      $numMeta = count($metaKeys);
      // there is supplemental meta data
      // display a column header for each metaData key
      $i = 0;
      while ($i < $numMeta) {
        echo '<th scope="col"><span>'.$metaKeys[$i]."</span></th>\n";
        $i++;
      }
    }
    if ($settings['debug'] > 0) {
      echo '<th scope="col"><span># API Requests</span></th>'."\n";
      echo '<th scope="col"><span>API Cost (units)</span></th>'."\n";
      // The next item has a footnote
      $numFootnotes++;
      $footnotes[$numFootnotes] = getFootNote('approxTotal');
      echo '<th scope="col"><span>Approx # Videos<sup>'.$numFootnotes.'</sup></span></th>'."\n";
    }
    echo '<th scope="col"><span># Videos</span></th>'."\n";
    echo '<th scope="col"><span># Captioned</span></th>'."\n";
    echo '<th scope="col"><span>% Captioned</span></th>'."\n";
    echo '<th scope="col"><span># '.ucfirst($settings['timeUnit'])."</span></th>\n";
    echo '<th scope="col"><span># '.ucfirst($settings['timeUnit']).' Captioned</span></th>'."\n";
    echo '<th scope="col"><span>Mean Views per Video</span></th>'."\n";
    echo '<th scope="col"><span>Max Views</span></th>'."\n";
    if (!$filter) {
      // no reason to separate out high traffic videos if already filtering for high traffic
      // The next several items share a footnote
      $numFootnotes++;
      $footnotes[$numFootnotes] = getFootNote('highTraffic');
      $highTrafficHeaders[] = '# Videos High Traffic';
      $highTrafficHeaders[] = '# Captioned High Traffic';
      $highTrafficHeaders[] = '% Captioned High Traffic';
      $highTrafficHeaders[] = '# '.ucfirst($settings['timeUnit']).' High Traffic';
      $highTrafficHeaders[] = '# '.ucfirst($settings['timeUnit']).' Captioned High Traffic';
      $numHighTrafficHeaders = count($highTrafficHeaders);
      $i=0;
      while ($i < $numHighTrafficHeaders) {
        echo '<th scope="col"><span>'.$highTrafficHeaders[$i];
        echo '<sup>'.$numFootnotes.'</sup></span></th>'."\n";
        $i++;
      }
    }
    echo "</tr>\n";
    echo '</thead>'."\n";
    echo '<tbody>'."\n";
  }
  elseif ($settings['output'] == 'xml') {
    echo '<channels>'."\n";
  }
  elseif ($settings['output'] == 'json') {
    // no output generated here - see showSummaryTableRow()
  }
  // write output immediatley to screen
  ob_flush();
  flush();
  return $footnotes;
}

function addMetaTags($output,$settings,$filter) {

  if ($output == 'xml') {
    echo '<meta>'."\n";
    echo '<report>'.$settings['report']."</report>\n";
    echo '<title>'.$settings['title']."</title>\n";
    echo '<time_unit>'.$settings['timeUnit']."</time_unit>\n";
    echo '<filter_type>'.$filter['type']."</filter_type>\n";
    echo '<filter_value>'.$filter['value']."</filter_value>\n";
    echo '<date>'.date('Y-m-d')."</date>\n";
    echo "</meta>\n";
  }
  elseif ($output == 'json') {
    echo '"meta":'."\n";
    echo "{\n";
    echo '"report": "'.$settings['report'].'",'."\n";
    echo '"title": "'.$settings['title'].'",'."\n";
    echo '"time_unit": "'.$settings['timeUnit'].'",'."\n";
    if ($filter['type']) {
      echo '"filter_type": "'.$filter['type'].'",'."\n";
    }
    else {
      echo '"filter_type": null,'."\n";
    }
    if ($filter['value']) {
      echo '"filter_value": "'.$filter['value'].'",'."\n";
    }
    else {
      echo '"filter_value": null,'."\n";
    }
    echo '"date": "'.date('Y-m-d').'"'."\n";
    echo "},\n";
  }
}

function showSummaryTableRow($settings,$rowNum,$numChannels,$channel=NULL,$nextChannelName=NULL,$metaData=NULL,$channelData,$filter,$highlights=NULL) {

  // $rowNum is either an integer, or 'totals'
  // $channel, $metaData, and $channelData are all arrays

  $numMeta = count($metaData);

  // calculate percentages and averages
  $pctCaptioned = round($channelData['cc']['count']/$channelData['all']['count'] * 100,1);
  if (!$filter) {
    // high traffic data is only included for non-filtered channels
    $pctCaptionedHighTraffic = round($channelData['ccHighTraffic']['count']/$channelData['highTraffic']['count'] * 100,1);
  }

  //  start of row
  if ($settings['output'] == 'html') {
    echo '<tr ';
    // add a data-status attribute that's used by ytca.js to populate the status message at the top of the page
    // this reflects the *next* channel, since it isn't written to the screen until the channel row is complete
    if ($nextChannelName) {
      $nextRow = $rowNum + 1;
      echo ' data-status="Processing Channel '.$nextRow.' of '.$numChannels.': '.$nextChannelName.'..."';
    }
    elseif ($rowNum == 'totals') {
      echo ' data-status="Analysis complete."';
    }

    if ($rowNum == 'totals') {
      echo ' class="totals" data-numMeta="'.$numMeta.'" >'."\n";
      // calculate colspan for Totals row header
      // always span ID and Name columns
      if ($settings['showChannelId']) { // span that too, plus all metadata columns
        $colSpan = $numMeta + 2;
      }
      else { // span all metadata columns
        $colSpan = $numMeta + 1;
      }
      echo '<th scope="row" colspan="'.$colSpan.'">TOTALS</th>'."\n";
    }
    else {
      $channelTitle = NULL;
      if ($highlights['use']) {
        if ($pctCaptioned >= $highlights['goodPct']) {
          $classes[] = 'goodChannel';
          $channelTitle = ' title="'.$highlights['goodLabel'].'"';
        }
        elseif ($pctCaptioned <= $highlights['badPct']) {
          $classes[] = 'badChannel';
          $channelTitle = ' title="'.$highlights['badLabel'].'"';
        }
      }
      if ($numMeta) {
        // add a class for each metadata value
        foreach ($metaData as $key => $value) {
          $classes[] = 'meta_'.$value;
        }
      }
      if (is_array($classes) && count($classes) > 0) {
        echo ' class="';
        $i=0;
        while ($i < count($classes)) {
          if ($i > 0) {
            echo ' ';
          }
          echo $classes[$i];
          $i++;
        }
        echo '"';
      }
      echo ">\n";
    }
  }
  elseif ($settings['output'] == 'xml' && $rowNum !== 'totals') {
    echo '<channel>'."\n";
  }
  elseif ($settings['output'] == 'json' && $rowNum !== 'totals') {
    echo "{\n";
  }

  // channel name (optionally linked to YouTube channel)
  if ($rowNum !== 'totals') {
    if ($settings['output'] == 'html') {
      echo '<th scope="row">';
      echo '<a href="https://www.youtube.com/channel/'.$channel['id'].'">';
      echo $channel['name'];
      echo '</a>';
      echo "</th>\n";
    }
    elseif ($settings['output'] == 'xml') {
      echo '<name>'.$channel['name']."</name>\n";
    }
    elseif ($settings['output'] == 'json') {
      echo '"name": "'.$channel['name'].'",'."\n";

    }
  }

  // channelId
  if ($rowNum !== 'totals') {
    if ($settings['showChannelId']) {
      if ($settings['output'] == 'html') {
        echo '<td>'.$channel['id']."</td>\n";
      }
      elseif ($settings['output'] == 'xml') {
        echo '<channelId>'.$channel['id']."</channelId>\n";
      }
      elseif ($settings['output'] == 'json') {
        echo '"channelId": "'.$channel['id'].'",'."\n";
      }
    }
  }

  // Display supplemental meta data, if any exists
  if ($rowNum !== 'totals') {
    if ($metaData) {
      foreach ($metaData as $key => $value) {
        if ($settings['output'] == 'html') {
          echo '<td>'.$value."</td>\n";
        }
        elseif ($settings['output'] == 'xml') {
          echo '<'.$key.'>'.$value.'</'.$key.'>'."\n";
        }
        elseif ($settings['output'] == 'json') {
          echo '"'.$key.'": "'.$value.'",'."\n";
        }
      }
    }
  }

  // Display data
  if ($settings['output'] == 'html') {
    if ($settings['debug'] > 0) {
      echo '<td class="data">'.number_format($channelData['all']['requests'])."</td>\n";
      echo '<td class="data">'.number_format($channelData['all']['cost'])."</td>\n";
      echo '<td class="data">'.number_format($channelData['all']['approxCount'])."</td>\n";
    }
    echo '<td class="data">'.number_format($channelData['all']['count'])."</td>\n";
    echo '<td class="data">'.number_format($channelData['cc']['count'])."</td>\n";
    echo '<td class="data">'.number_format($pctCaptioned,1)."%</td>\n";
    echo '<td class="data">'.formatDuration($channelData['all']['duration'],$settings['timeUnit'])."</td>\n";
    echo '<td class="data">'.formatDuration($channelData['cc']['duration'],$settings['timeUnit'])."</td>\n";
    if ($rowNum == 'totals') {
      echo '<td class="data">--</td>'."\n";
    }
    else {
      echo '<td class="data">'.number_format($channelData['all']['avgViews'])."</td>\n";
    }
    echo '<td class="data">'.number_format($channelData['all']['maxViews'])."</td>\n";
    if (!$filter) {
      // high traffic data is only included for non-filtered channels
      echo '<td class="data">'.number_format($channelData['highTraffic']['count'])."</td>\n";
      echo '<td class="data">'.number_format($channelData['ccHighTraffic']['count'])."</td>\n";
      echo '<td class="data">'.number_format($pctCaptionedHighTraffic,1)."%</td>\n";
      echo '<td class="data">'.formatDuration($channelData['highTraffic']['duration'],$settings['timeUnit'])."</td>\n";
      echo '<td class="data">'.formatDuration($channelData['ccHighTraffic']['duration'],$settings['timeUnit'])."</td>\n";
    }
  }
  elseif ($settings['output'] == 'xml') {
    if ($rowNum !== 'totals') { // no totals in xml output
      if ($settings['debug'] > 0) {
        echo '<num_api_requests>'.number_format($channelData['all']['requests'])."</num_api_requests>\n";
        echo '<api_cost>'.number_format($channelData['all']['cost'])."</api_cost>\n";
        echo '<num_videos_est>'.number_format($channelData['all']['approxCount'])."</num_videos_est>\n";
      }
      echo '<num_videos>'.number_format($channelData['all']['count'])."</num_videos>\n";
      echo '<num_captioned>'.number_format($channelData['cc']['count'])."</num_captioned>\n";
      echo '<pct_captioned>'.number_format($pctCaptioned,1)."</pct_captioned>\n";

      // num_seconds (or num_minutes or num_hours, depending on value of timeUnit)
      echo '<num_'.strtolower($settings['timeUnit']).'>';
      echo formatDuration($channelData['all']['duration'],$settings['timeUnit']);
      echo '</num_'.strtolower($settings['timeUnit']).">\n";

      // num_seconds_captioned (or comparable element name for minutes or hours, depending on value of timeUnit)
      echo '<num_'.strtolower($settings['timeUnit']).'_captioned>';
      echo formatDuration($channelData['cc']['duration'],$settings['timeUnit']);
      echo '</num_'.strtolower($settings['timeUnit'])."_captioned>\n";

      echo '<avg_views>'.number_format($channelData['all']['avgViews'])."</avg_views>\n";
      echo '<max_views>'.number_format($channelData['all']['maxViews'])."</max_views>\n";

      if (!$filter) {
        // high traffic data is only included for non-filtered channels
        echo '<num_high_traffic>'.number_format($channelData['highTraffic']['count'])."</num_high_traffic>\n";
        echo '<num_captioned_high_traffic>'.number_format($channelData['ccHighTraffic']['count'])."</num_captioned_high_traffic>\n";
        echo '<pct_captioned_high_traffic>'.number_format($pctCaptionedHighTraffic,1)."%</pct_captioned_high_traffic>\n";

        // num_seconds_high_traffic (or comparable element name for minutes or hours, depending on value of timeUnit)
        echo '<num_'.strtolower($settings['timeUnit']).'_high_traffic>';
        echo formatDuration($channelData['highTraffic']['duration'],$settings['timeUnit']);
        echo '</num_'.strtolower($settings['timeUnit'])."_high_traffic>\n";

        // num_seconds_captioned_high_traffic (or comparable element name for minutes or hours, depending on value of timeUnit)
        echo '<num_'.strtolower($settings['timeUnit']).'_captioned_high_traffic>';
        echo formatDuration($channelData['ccHighTraffic']['duration'],$settings['timeUnit']);
        echo '</num_'.strtolower($settings['timeUnit'])."_captioned_high_traffic>\n";
      }
    }
  }
  elseif ($settings['output'] == 'json') {
    if ($rowNum !== 'totals') { // no totals in json output
      if ($settings['debug'] > 0) {
        echo '"num_api_requests": "'.number_format($channelData['all']['requests']).'",'."\n";
        echo '"api_cost": "'.number_format($channelData['all']['cost']).'",'."\n";
        echo '"num_videos_est": "'.number_format($channelData['all']['approxCount']).'",'."\n";
      }
      echo '"num_videos": "'.number_format($channelData['all']['count']).'",'."\n";
      echo '"num_captioned": "'.number_format($channelData['cc']['count']).'",'."\n";
      echo '"pct_captioned": "'.number_format($pctCaptioned,1).'",'."\n";

      // num_seconds (or num_minutes or num_hours, depending on value of timeUnit)
      echo '"num_'.strtolower($settings['timeUnit']).'": "';
      echo formatDuration($channelData['all']['duration'],$settings['timeUnit']).'",'."\n";

      // num_seconds_captioned (or comparable element name for minutes or hours, depending on value of timeUnit)
      echo '"num_'.strtolower($settings['timeUnit']).'_captioned": "';
      echo formatDuration($channelData['cc']['duration'],$settings['timeUnit']).'",'."\n";

      echo '"avg_views": "'.number_format($channelData['all']['avgViews']).'",'."\n";
      if ($filter) {
        // max_views is the last element (no comma)
        echo '"max_views": "'.number_format($channelData['all']['maxViews']).'"'."\n";
      }
      else {
        echo '"max_views": "'.number_format($channelData['all']['maxViews']).'",'."\n";

        // high traffic data is only included for non-filtered channels
        echo '"num_high_traffic": "'.number_format($channelData['highTraffic']['count']).'",'."\n";
        echo '"num_captioned_high_traffic": "'.number_format($channelData['ccHighTraffic']['count']).'",'."\n";
        echo '"pct_captioned_high_traffic": "'.number_format($pctCaptionedHighTraffic,1).'%",'."\n";

        // num_seconds_high_traffic (or comparable element name for minutes or hours, depending on value of timeUnit)
        echo '"num_'.strtolower($settings['timeUnit']).'_high_traffic": "';
        echo formatDuration($channelData['highTraffic']['duration'],$settings['timeUnit']).'",'."\n";

        // num_seconds_captioned_high_traffic (or comparable element name for minutes or hours, depending on value of timeUnit)
        echo '"num_'.strtolower($settings['timeUnit']).'_captioned_high_traffic": "';
        echo formatDuration($channelData['ccHighTraffic']['duration'],$settings['timeUnit']).'"'."\n";
      }
    }
  }

  // end of row
  if ($settings['output'] == 'html') {
    echo "</tr>\n";
  }
  elseif ($settings['output'] == 'xml' && $rowNum !== 'totals') {
    echo "</channel>\n";
  }
  elseif ($settings['output'] == 'json' && $rowNum !== 'totals') {
    if ($rowNum == $numChannels) { // this is the last channel; no comma
      echo "}\n";
    }
    else {
      echo "},\n";
    }
  }

  // write output immediately to screen
  ob_flush();
  flush();
}

function showSummaryTableBottom($output,$footnotes=NULL) {

  if ($output == 'html') {
    echo "</tbody>\n";
    echo "</table>\n";

    if ($footnotes) {
      $numFootnotes = count($footnotes);
      if ($numFootnotes > 0) {
        $i = 1;
        while ($i <= $numFootnotes) {
          echo '<p class="footnote">';
          echo '<sup>'.$i.'</sup> ';
          echo $footnotes[$i];
          echo "</p>\n";
          $i++;
        }
      }
    }
  }
  elseif ($output == 'xml') {
    echo "</channels>\n";
  }
  elseif ($output == 'json') {
    echo "]\n"; // end "channels"
  }
}

function showBottom($output) {

  if ($output == 'html'){
    echo "</body>\n";
    echo "</html>";
  }
  elseif ($output == 'xml') {
    echo '</ytca>';
  }
  elseif ($output == 'json') {
    echo "}\n"; // end "ytca"
    echo "}"; // end json
  }
}

function showDetails($settings,$rowNum,$numChannels,$channel,$channelMeta,$channelData,$videos,$numVideos,$filter,$sortBy) {

  // $channel is an array that includes 'id', 'name', plus 'videos' (an array of *unfiltered* videos)
  // $channelMeta is an array of metadata fields and their values for this channel
  // $channelData is an array of statistical summary data for this channel
  // $videos is an array of *filtered* videos (if filters are used, this is a subset of $channel['videos'])
  if (is_countable($channelMeta)){
  $numMeta = count($channelMeta);
  }
  // calculate percentages
  $pctCaptioned = round($channelData['cc']['count']/$channelData['all']['count'] * 100,1);

  if ($settings['output'] == 'html') {

    echo '<h2>Channel '.$rowNum.' of '.$numChannels.': '.$channel['name']."</h2>\n";

    // show a list of summary data

    echo '<ul class="channelDetails">'."\n";
    // link to YouTube channel
    if ($settings['showChannelId']) {
      $channelLink = 'https://www.youtube.com/channel/'.$channel['id'];
      echo '<li><a href="'.$channelLink.'">'.$channelLink.'</a></li>'."\n";
    }

    // channel meta data
    if ($numMeta) {
      foreach ($channelMeta as $key => $value) {
        echo '<li>'.$key.': <span class="value">'.$value."</span></li>\n";
      }
    }

    if ($settings['debug'] > 0) {
      echo '<li>Number of API requests: <span class="value">';
      echo number_format($channelData['all']['requests'])."</span></li>\n";
      echo '<li>API cost (units): <span class="value">';
      echo number_format($channelData['all']['cost'])."</span></li>\n";
      echo '<li>Number of videos (estimated): <span class="value">';
      echo number_format($channelData['all']['approxCount'])."</span></li>\n";
    }

    // Number of videos
    if ($filter) {
      // if videos are filtered, show count for both filtered and unfiltered
      echo '<li>Number of videos (unfiltered): ';
      echo '<span class="value">'.number_format(count($channel['videos'])-2).'</span></li>'."\n";

      // and filtered
      echo '<li>Number of videos (filtered): ';
      echo '<span class="value">'.number_format($channelData['all']['count']).'</span></li>'."\n";
    }
    else {
      // no filter? Show count for all videos
      echo '<li>Number of videos: ';
      echo '<span class="value">'.number_format($channelData['all']['count']).'</span></li>'."\n";
    }

    // Number / percent captioned
    echo '<li>Number captioned: <span class="value">';
    echo number_format($channelData['cc']['count']).'</span> ';
    echo '(<span class="value">'.number_format($pctCaptioned,1).'%</span>)</li>'."\n";

    // Duration
    echo '<li>Total '.$settings['timeUnit'].': <span class="value">';
    echo formatDuration($channelData['all']['duration'],$settings['timeUnit']).'</span></li>'."\n";

    // Duration (captioned)
    echo '<li>'.ucfirst($settings['timeUnit']).' captioned: <span class="value">';
    echo formatDuration($channelData['cc']['duration'],$settings['timeUnit'])."</span></td>\n";

    // Avg views:
    echo '<li>Average views: <span class="value">'.number_format($channelData['all']['avgViews'])."</span></li>\n";

    if (!$filter) {
      // high traffic data is only included for non-filtered channels
      $pctCaptionedHighTraffic = round($channelData['ccHighTraffic']['count']/$channelData['highTraffic']['count'] * 100,1);
      echo '<li>Number of high traffic videos: <span class="value">';
      echo number_format($channelData['highTraffic']['count'])."</span></li>\n";
      echo '<li>Number captioned (high traffic): <span class="value">';
      echo number_format($channelData['ccHighTraffic']['count']).'</span> ';
      echo '(<span class="value">'.number_format($pctCaptionedHighTraffic,1)."%</span>)</li>\n";
      echo '<li>'.ucfirst($settings['timeUnit']).' captioned (high traffic): <span class="value">';
      echo formatDuration($channelData['ccHighTraffic']['duration'],$settings['timeUnit'])."</span></li>\n";
    }

    echo "</ul>\n";

    ob_flush();
    flush();
  }
  elseif ($settings['output'] == 'xml') {
    echo '<channel>'."\n";
    echo '<name>'.$channel['name']."</name>\n";
    if ($numMeta) {
      foreach ($channelMeta as $key => $value) {
        echo '<'.$key.'>'.$value.'</'.$key.">\n";
      }
    }
    echo '<num_videos>'.number_format($channelData['all']['count'])."</num_videos>\n";
    echo '<num_captioned>'.number_format($channelData['cc']['count'])."</num_captioned>\n";
    echo '<pct_captioned>'.number_format($pctCaptioned,1)."</pct_captioned>\n";
    // num_seconds, num_minutes, or num_hours (depending on timeUnit)
    echo '<num_'.strtolower($settings['timeUnit']).'>';
    echo formatDuration($channelData['all']['duration'],$settings['timeUnit']);
    echo '</num_'.strtolower($settings['timeUnit']).">\n";
    // num_seconds_captioned (or comparable element name for minutes or hours, depending on value of timeUnit)
    echo '<num_'.strtolower($settings['timeUnit']).'_captioned>';
    echo formatDuration($channelData['cc']['duration'],$settings['timeUnit']);
    echo '</num_'.strtolower($settings['timeUnit'])."_captioned>\n";
    echo '<max_views>'.number_format($channelData['all']['maxViews'])."</max_views>\n";
  }
  elseif ($settings['output'] == 'json') {
    echo "{\n";
    echo '"name": "'.$channel['name'].'",'."\n"; // model only
    if ($numMeta) {
      foreach ($channelMeta as $key => $value) {
        echo '"'.$key.'": "'.$value.'",'."\n";
      }
    }
    echo '"num_videos": "'.number_format($channelData['all']['count']).'",'."\n";
    echo '"num_captioned": "'.number_format($channelData['cc']['count']).'",'."\n";
    echo '"pct_captioned": "'.number_format($pctCaptioned,1).'",'."\n";
    // num_seconds, num_minutes, or num_hours (depending on timeUnit)
    echo '"num_'.strtolower($settings['timeUnit']).'": "';
    echo formatDuration($channelData['all']['duration'],$settings['timeUnit']).'",'."\n";
    // num_seconds_captioned (or comparable element name for minutes or hours, depending on value of timeUnit)
    echo '"num_'.strtolower($settings['timeUnit']).'_captioned": "';
    echo formatDuration($channelData['cc']['duration'],$settings['timeUnit']).'",'."\n";
    if ($numVideos) {
      // max_views is not the last element; follow it with a comma
      echo '"max_views": "'.number_format($channelData['all']['maxViews']).'",'."\n";
    }
    else {
      echo '"max_views": "'.number_format($channelData['all']['maxViews']).'"'."\n";
    }
  }

  if ($numVideos) {

    if ($settings['output'] == 'html') {
      // show table head
      echo '<table class="details">'."\n";
      echo '<thead>'."\n";
      echo '<tr>'."\n";
      // title (sortable)
      echo '<th scope="col"';
      if ($sortBy == 'title') {
        echo ' aria-sort="ascending"';
      }
      echo '><span>Video Title</span></th>'."\n";
      // date (sortable)
      echo '<th scope="col"';
      if ($sortBy == 'date') {
        echo ' aria-sort="descending"';
      }
      echo '><span>Date</span></th>'."\n";
      // duration, captioned (not sortable via YouTube API)
      echo '<th scope="col"><span>Duration</span></th>'."\n";
      echo '<th scope="col"><span>Captioned</span></th>'."\n";
      // views (sortable)
      echo '<th scope="col"';
      if ($sortBy == 'viewCount') {
        echo ' aria-sort="descending"';
      }
      echo '><span>Views</span></th>'."\n";
      echo "</tr>\n";
      echo "</thead>\n";
      echo '<tbody>'."\n";
    }
    elseif ($settings['output'] == 'xml') {
      echo '<videos>'."\n";
    }
    elseif ($settings['output'] == 'json') {
      echo '"videos": [';
    }

    ob_flush();
    flush();

    $i=0;
    while ($i < $numVideos) {

      if ($settings['output'] == 'html') {

        // show table row
        echo '<tr>'."\n";
        echo '<td><a href="https://youtu.be/'.$videos[$i]['id'].'">'.$videos[$i]['title']."</a></td>\n";
        echo '<td>'.$videos[$i]['date']."</td>\n";
        echo '<td>'.convertToHMS($videos[$i]['duration'])."</td>\n";
        if ($videos[$i]['captions'] == 'true') {
          echo '<td class="ccYes">Yes</td>'."\n";
        }
        elseif ($videos[$i]['captions'] == 'false') {
          echo '<td class="ccNo">No</td>'."\n";
        }
        echo '<td class="data">'.number_format($videos[$i]['views'])."</td>\n";
        echo "</tr>\n";
      }
      elseif ($settings['output'] == 'xml') {
        echo '<video>'."\n";
        echo '<youtube_id>'.$videos[$i]['id']."</youtube_id>\n";
        echo '<title>'.htmlspecialchars($videos[$i]['title'], ENT_XML1, 'UTF-8')."</title>\n";
        echo '<date>'.$videos[$i]['date']."</date>\n";
        echo '<duration>'.convertToHMS($videos[$i]['duration'])."</duration>\n";
        if ($videos[$i]['captions'] == 'true') {
          echo '<captioned>Yes</captioned>'."\n";
        }
        elseif ($videos[$i]['captions'] == 'false') {
          echo '<captioned>No</captioned>'."\n";
        }
        echo '<views>'.number_format($videos[$i]['views'])."</views>\n";
        echo '</video>'."\n";
      }
      elseif ($settings['output'] == 'json') {
        echo "{\n"; // open video array
        echo '"youtube_id": "'.$videos[$i]['id'].'",'."\n";
        echo '"title": "'.htmlspecialchars($videos[$i]['title'], ENT_XML1, 'UTF-8').'",'."\n";
        echo '"date": "'.$videos[$i]['date'].'",'."\n";
        echo '"duration": "'.convertToHMS($videos[$i]['duration']).'",'."\n";
        if ($videos[$i]['captions'] == 'true') {
          echo '"captioned": "Yes",'."\n";
        }
        elseif ($videos[$i]['captions'] == 'false') {
          echo '"captioned": "No",'."\n";
        }
        echo '"views": "'.number_format($videos[$i]['views']).'"'."\n";
        if ($i == $numVideos - 1) {
          echo "}\n"; // close video object (this is the last video, so no comma)
          echo "]\n"; // end videos array
        }
        else {
          echo "},\n"; // close video object
        }
      }
      ob_flush();
      flush();
      $i++;
    }

    if ($settings['output'] == 'html') {
      echo '</tbody>'."\n";
      echo "</table>\n";
    }
    elseif ($settings['output'] == 'xml') {
      echo '</videos>'."\n";
      echo '</channel>'."\n";
      if ($rowNum == $numChannels) { // this is the last channel
        echo "</channels>\n";
      }
    }
    elseif ($settings['output'] == 'json') {
      // end channel array
      if ($rowNum == $numChannels) {
        echo "}\n"; // close channel object (this is the last channel; so no comma)
        echo "]\n"; // also end channels array
      }
      else {
        echo "},\n"; // close channel object
      }
    }
  }
  else {
    if ($settings['output'] == 'html') {
      echo '<p>No videos to show.</p>'."\n";
    }
    ob_flush();
    flush();
  }
}

function isValid($var, $value, $filterType=NULL) {

  // returns true if $value is a valid value of $var
  // $filterType is included if $var == 'filterValue'

  if ($var == 'filterValue') {
    if ($filterType == 'percentile') {
      if ($value > 0 && $value < 100) {
        return true;
      }
    }
    else {
      if (is_int(strval($value))) {
        return true;
      }
      if (is_numeric($value) && $value > 0) {
        return true;
      }
    }
  }
  elseif ($var == 'title' || $var == 'channels') {
    // not currently any rules for these vars
    // may need to add some
    // For example, could check to see if title is properly URL-encoded
    return true;
  }
  elseif ($var == 'date') {
    // date-start and date-end must be in the format YYYY-MM-DD
    if (strlen($value) == 10) {
      $year = substr($value,0,4);
      $month = ltrim(substr($value,5,2),'0');
      $day = ltrim(substr($value,8,2),'0');
      if ($year > 1800 && $year <= date('Y')) {
        if ($month >=1 && $month <= 12) {
          if ($day >= 1 && $day <= 31) {
            return true;
          }
        }
      }
      return false;
    }
    else {
      return false;
    }
  }
  else {
    if ($var == 'output') {
      $allowed = array('html','xml','json');
    }
    elseif ($var == 'report') {
      $allowed = array('summary','details');
    }
    elseif ($var == 'filterType') {
      $allowed = array('views','percentile','count');
    }
    elseif ($var == 'timeUnit') {
      $allowed = array('seconds','minutes','hours');
    }
    if (in_array($value,$allowed)) {
      return true;
    }
  }
  return false;
}

function fileGetContents($url) {

  // PHP fileGetContents() is resulting in intermittent errors
  // Not sure why but curl seems to be more reliable
  if (!function_exists('curl_init')){
    die('CURL is not installed!');
  }
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $output = curl_exec($ch);
  curl_close($ch);
  return $output;
}

function getChannelId($apiKey,$userName) {

  // returns an array with 'id' and 'cost'

  $query = buildYouTubeQuery('channels', NULL, $userName, $apiKey);
  $output['cost'] = $query['cost']; // cost is charged, even if query is unsuccessful
  $output['id'] = null; // will be replaced with channelId if successful
  if ($content = fileGetContents($query['url'])) {
    $json = json_decode($content,true);
    $channelId = $json['items'][0]['id'];
    if (isChannelId($channelId)) {
      $output['id'] = $channelId;
    }
  }
  return $output;
}

function getChannelName($apiKey,$channelId) {

  // returns an array with 'name' and 'cost'

  $query = buildYouTubeQuery('channels', $channelId, NULL, $apiKey);
  $output['cost'] = $query['cost']; // cost is charged, even if query is unsuccessful
  $output['name'] = null; // will be replaced with channel name (title) if successful
  if ($content = fileGetContents($query)) {
    $json = json_decode($content,true);
    $output['name'] = $json['items'][0]['snippet']['title'];
  }
  return $output;
}

function isChannelId($string) {

  if (strlen(trim($string)) == 24) {
    if (substr($string,0,2) == 'UC') {
      return true;
    }
  }
  return false;
}

function getChannelMeta($channels) {

  // if any channel in the array has more than 2 keys
  // the extra keys are metadata that will be applied uninformly to all channels
  // (those with no matching metadata will just having missing data)

  // first, retrieve all metadata keys
  $i=0;
  while ($i < count($channels)) {
    $keys = array_keys($channels[$i]);
    $numKeys = count($keys);
    if ($numKeys > 2) {
      // this channel has meta keys
      $j=0;
      while ($j < $numKeys) {
        $key = $keys[$j];
        if ($key !== 'name' && $key !== 'id') {
          if (!in_array($key, $metaKeys)) {
            // this is a new key, not yet added to array
            $metaKeys[] = $key;
          }
        }
        $j++;
      }
    }
    $i++;
  }
  // second, if metadata keys were found,
  // build an array of each channel's data for each key
  if (is_countable($metaKeys)){
  $numMeta = count($metaKeys);
  }
  if ($numMeta > 0) {
    $i=0;
    while ($i < count($channels)) {
      $j=0;
      while ($j < $numMeta) {
        $key = $metaKeys[$j];
        $metaData[$i][$key] = $channels[$i][$key];
        $j++;
      }
      $i++;
    }
    return $metaData;
  }
  else {
    return false;
  }
}

function buildYouTubeQuery($which, $id=NULL, $userName=NULL, $apiKey, $sortBy=NULL, $nextPageToken=NULL) {

  // $which is either 'search', 'channels', or 'videos'
  // $id is a channel ID for 'search' queries; or a video ID for 'videos' query
  // For 'channels' queries, $id is the channelId if known; otherwise $username is provided instead
  // $sortBy is either 'viewCount', 'date', or 'title'
  // returns an array with the following keys '
  //  'cost' - the Google cost for executing this query
  //  'url' -

  if ($which == 'search') {
    // used for collecting data for all videos within a channel
    $request = 'https://www.googleapis.com/youtube/v3/search?';
    $request .= 'key='.$apiKey;
    $request .= '&channelId='.$id;
     // check if date-start is set
    if (isset($_GET['date-start'])) {
      if (isValid('date',$_GET['date-start'])) {
        $request .= '&publishedAfter='.formatDateForYoutube($_GET['date-start']);
      }
    }

    // check if date-start is set
    if (isset($_GET['date-end'])) {
      if (isValid('date',$_GET['date-end'])) {
        $request .= '&publishedBefore='.formatDateForYoutube($_GET['date-end']);
      }
    }
    $request .= '&part=id,snippet';
    if ($sortBy) {
      $request .= '&order='.$sortBy;
    }
    $request .= '&maxResults=50';
    if ($nextPageToken) {
      $request .= '&pageToken='.$nextPageToken;
    }
    // Documentation of this method (includes cost):
    // https://developers.google.com/youtube/v3/docs/search/list
    $cost = 100;
  }
  elseif ($which == 'channels') {
    // Cheaper than search, but doesn't include individual video data (not even ids)
    // This is currently only used for looking up channel IDs or names
    $request = 'https://www.googleapis.com/youtube/v3/channels?';
    $request .= 'key='.$apiKey;
    if ($userName) {
      $request .= '&forUsername='.$userName;
    }
    elseif ($id) {
      $request .= '&id='.$id;
    }
    $request .= '&part=id,snippet';
    $request .= '&maxResults=1';
    // Documentation of this method (includes cost):
    // https://developers.google.com/youtube/v3/docs/channels/list
    // Cost = 1 unit + additional units for each specified resource part:
    // id = 0 units
    // snippet = 2 units
    // Total = 3 units
    $cost = 3;
  }
  elseif ($which == 'videos') {
    // used for collecting data about an individual video
    $request = 'https://www.googleapis.com/youtube/v3/videos?';
    $request .= 'key='.$apiKey;
    $request .= '&id='.$id;
    $request .= '&part=contentDetails,statistics';
    $request .= '&maxResults=1';
    // Documentation of this method (includes cost):
    // https://developers.google.com/youtube/v3/docs/videos/list
    // Cost = 1 unit + additional units for each specified resource part:
    // contentDetails = 2
    // statistics = 2
    // Total = 5 units
    $cost = 5;
  }
  $query['url'] = $request;
  $query['cost'] = $cost;
  return $query;
}

function getVideos($settings,$channelId,$json,$numVideos,$apiKey,$sortBy) {

  // returns an array $videos with all data for individual videos within this channel
  // output array also includes two auditing keys: 'requests' and 'cost'

  $maxResults = 50; // as defined by YouTube API
  $requests = 0; // a counter of number of calls to the API
  $cost = 0; // a running cost for this channel

  if ($numVideos <= $maxResults) {
    $numQueries = 1;
    $finalBalance = $numVideos;
  }
  else {
    // will need to query Google multiple times to get all data
    $numQueries = ceil($numVideos/$maxResults);
    $finalBalance = $numVideos % $maxResults;
  }
  $v=0; // index for videos array
  $q=0; // index for queries
  while ($q < $numQueries) {

    if ($q == ($numQueries-1)) {
      // this is the last query
      // therefore it has fewer items
      $finalIndex = $finalBalance;
    }
    else {
      $finalIndex = $maxResults;
    }

    // get json data, if needed
    if ($q > 0) {
      // this is NOT the first query.
      // Therefore we need to refresh $json with the next page of data
      $n = $q + 1;

      $nextPageToken = $json['nextPageToken'];
      $channelQueryArray = buildYouTubeQuery('search',$channelId,NULL,$apiKey,$sortBy,$nextPageToken);
      $channelQuery = $channelQueryArray['url'];
      $cost += $channelQueryArray['cost'];
      $requests++;

      if ($settings['debug'] == 2 && $settings['output'] == 'html') {
        echo '<div class="ytca_debug ytca_channel_query">';
        echo '<span class="query_label">Channel query #'.$n.' of '.$numQueries.':</span>'."\n";
        echo '<span class="query_url"><a href="'.$channelQuery.'">'.$channelQuery."</a></span>\n";
        echo "</div>\n";
      }
      if ($content = fileGetContents($channelQuery)) {
        $json = json_decode($content,true);
      }
    }

    // now step through each item in the search query results, collecting data about each video
    $i=0;
    while ($i < $finalIndex) {
      $n = $i + 1;
      $videoId = $json['items'][$i]['id']['videoId'];
      if ($videoId) {
        // get details about this video via a 'videos' query
        $videoQueryArray = buildYouTubeQuery('videos', $videoId, NULL, $apiKey);
        $videoQuery = $videoQueryArray['url'];
        $cost += $videoQueryArray['cost'];
        $requests++;
        if ($settings['debug'] == 2 && $settings['output'] == 'html') {
          echo '<div class="ytca_debug">';
          echo '<span class="query_label">Video query #'.$n.' of '.$finalIndex.':</span>'."\n";
          echo '<span class="query_url"><a href="'.$videoQuery.'">'.$videoQuery."</a></span>\n";
          echo "</div>\n";
        }
        if ($videoContent = fileGetContents($videoQuery)) {
          $videos[$v]['id'] = $videoId;
          $videos[$v]['title'] = $json['items'][$i]['snippet']['title'];
          $videos[$v]['date'] = formatDate('youtube','ymd',$json['items'][$i]['snippet']['publishedAt']);
          $videoJson = json_decode($videoContent,true);
          $videos[$v]['duration'] = $videoJson['items'][0]['contentDetails']['duration']; // in seconds
          $videos[$v]['captions'] = $videoJson['items'][0]['contentDetails']['caption']; // 'true' or 'false'
          $videos[$v]['views'] = $videoJson['items'][0]['statistics']['viewCount'];
          $videos[$v]['query'] = $videoQuery; // added for debugging purposes
          $videos[$v]['status'] = 'Success!'; // added for debugging purposes
          $v++;
        }
      }
      $i++;
    }
    $q++;
  }
  $videos['requests'] = $requests;
  $videos['cost'] = $cost;
  return $videos;
}

function applyFilter($videos,$filter) {
  // $videos is an array of video data
  // $filter is an array with 'type' and 'value'
  // All filters are based on views
  // First, must sort $videos array by views DESC
  $numVideos = count($videos);

  if ($filter['type'] == 'views') {
    // include only videos with X views
    $i=0;
    while ($i < $numVideos) {
      if ($videos[$i]['views'] >= $filter['value']) {
        $v[] = $videos[$i];
      }
      $i++;
    }
  }
  elseif ($filter['type'] == 'percentile') {
    // include only videos in the Xth percentile (based on views)
    $percentile = $filter['value'];
    $targetIndex = floor(($percentile/100) * $numVideos);
    $i = 0;
    while ($i <= $targetIndex) {
      $v[] = $videos[$i];
      $i++;
    }
  }
  elseif ($filter['type'] == 'count') {
    // include only videos in the Top X (based on views)
    if ($filter['value'] < $numVideos) {
      $i=0;
      while ($i < $filter['value']) {
        $v[] = $videos[$i];
        $i++;
      }
    }
    else {
      // there are fewer than X videos in this channel.
      // Return all videos
      $v = $videos;
    }
  }
  else {
    // there is no filter
    $v = $videos;
  }
  if ($filter['dateStart'] || $filter['dateEnd']) {
    // $v = filterByDate($v,$filter);
  }
  return $v;
}

function filterBydate($videos,$filter) {

  $numVideos = count($videos);
  if ($numVideos > 0) {
    $i = 0;
    while ($i <= $numVideos) {
      if ($filter['dateStart'] && $filter['dateEnd']) {
        if ($videos[$i]['date'] >= $filter['dateStart'] && $videos[$i]['date'] <= $filter['dateEnd']) {
          $v[] = $videos[$i];
        }
      }
      elseif ($filter['dateStart']) {
        if ($videos[$i]['date'] >= $filter['dateStart']) {
          $v[] = $videos[$i];
        }
      }
      elseif ($filter['dateEnd']) {
        if ($videos[$i]['date'] <= $filter['dateEnd']) {
          $v[] = $videos[$i];
        }
      }
      $i++;
    }
    return $v;
  }
}

function sortVideos($videos,$field,$direction) {

  // return $videos array sorted by views
  // No longer used; results already sorted via order parameter in YouTube API query
  // Preserved here for reference
  // $field is any key within $videos array
  // direction is either SORT_ASC or SORT_DESC
  foreach ($videos as $key=>$row) {
    $sorted[$key] = $row[$field];
  }
  array_multisort($sorted, $direction, $videos);
  return $videos;
}

function calcDuration($videos,$numVideos,$captioned=NULL,$viewThreshold=NULL) {

  // returns total duration of all videos, in seconds
  // if $captioned is 'true' or 'false', filter videos based on that value
  // if $viewThreshold > 0, count only videos with views > that threshold
  $i=0;
  $seconds=0;
  while ($i < $numVideos) {
    $duration = NULL;
    if ($captioned === 'true') {
      if ($videos[$i]['captions'] === 'true') {
        if ($viewThreshold) {
          if ($videos[$i]['views'] > $viewThreshold) {
            $duration = $videos[$i]['duration'];
          }
        }
        else {
          $duration = $videos[$i]['duration'];
        }
      }
    }
    elseif ($captioned === 'false') {
      if ($videos[$i]['captions'] === 'false') {
        if ($viewThreshold) {
          if ($videos[$i]['views'] > $viewThreshold) {
            $duration = $videos[$i]['duration'];
          }
        }
        else {
          $duration = $videos[$i]['duration'];
        }
      }
    }
    else { // include all videos, regardless of captions
      if ($viewThreshold) {
        if ($videos[$i]['views'] > $viewThreshold) {
          $duration = $videos[$i]['duration'];
        }
      }
      else {
        $duration = $videos[$i]['duration'];
      }
    }
    $seconds += convertToSeconds($duration);
    $i++;
  }
  return $seconds;
}

function countViews($videos,$numVideos) {

  // returns array with keys 'count' and 'max'
  $i=0;
  $count=0;
  $max=0;
  while ($i < $numVideos) {
    $count += $videos[$i]['views'];
    if ($videos[$i]['views'] > $max) {
      $max = $videos[$i]['views'];
    }
    $i++;
  }
  $result['count'] = $count;
  $result['max'] = $max;
  return $result;
}

function countHighTraffic ($videos,$numVideos,$threshold) {

  // returns number of videos with traffic above $threshold (i.e., "high traffic")
  $i=0;
  $count=0;
  while ($i < $numVideos) {
    if ($videos[$i]['views'] > $threshold) {
      $count++;
    }
    $i++;
  }
  return $count;
}

function convertToSeconds($duration) {

  // $duration is in ISO 8601 format
  // https://developers.google.com/youtube/v3/docs/videos#contentDetails.duration
  // for videos < 1 hour: "PT#M#S"
  // for videos >= 1 hour: "PT#H#M#S"
  if (!empty($duration)) {
    $interval = new DateInterval($duration);
    $seconds = ($interval->h*3600)+($interval->i*60)+($interval->s);
    return $seconds;
  }
  else {
    return 0;
  }
}

function convertToHMS($duration) {

  // see comments above about $duration dormat
  // convert to HH:MM:SS
  if (!empty($duration)) {
    $interval = new DateInterval($duration);
    $hours = sprintf("%02d",$interval->h);
    $minutes = sprintf("%02d",$interval->i);
    $seconds = sprintf("%02d",$interval->s);
    return $hours.':'.$minutes.':'.$seconds;
  }
  else {
    return '00:00:00';
  }
}

function makeTimeReadable($seconds) {

  $hours = floor($seconds / 3600);
  $minutes = floor(($seconds / 60) % 60);
  $seconds = $seconds % 60;
  $time = '';
  if ($hours) {
    $time .= $hours.' hour';
    if ($hours > 1) {
      $time .= 's';
    }
    $time .= ', ';
  }
  if ($minutes) {
    $time .= $minutes.' minute';
    if ($minutes > 1) {
      $time .= 's';
    }
    $time .= ', ';
  }
  $time .= $seconds.' seconds';
  return $time; // I've had enough of it!
}

function formatDuration($seconds, $timeUnit) {

  // $timeUnit is either 'seconds', 'minutes', or 'hours'
  if ($timeUnit == 'seconds') {
    return number_format($seconds);
  }
  elseif ($timeUnit == 'minutes') {
    return number_format(($seconds / 60), 2);
  }
  elseif ($timeUnit == 'hours') {
    return number_format(($seconds / 3600), 2);
  }
}

function formatDate($from,$to,$date) {

  // $from is either 'youtube' (e.g., "2016-04-12T16:26:51.000Z")
  // or 'ymd' (e.g., "2016-04-12")
  // $to is either 'ymd' or 'friendly' (e.g., 'Apr 12, 2016')

  $ts = strtotime($date);

  if ($to == 'ymd') {
    return date('Y-m-d', $ts);
  }
  elseif ($to == 'friendly') {
    return date('M d, Y', $ts);
  }
  return false;
}

function countCaptioned($videos,$numVideos,$viewThreshold=NULL) {

  // if $viewThreshold > 0, count only videos with views > that threshold
  $i=0;
  $c=0;
  while ($i < $numVideos) {
    if ($videos[$i]['captions'] === 'true') {
      if ($viewThreshold) {
        if ($videos[$i]['views'] > $viewThreshold) {
          $c++;
        }
      }
      else {
        $c++;
      }
    }
    $i++;
  }
  return $c;
}

function getOrdinalSuffix($value) {

  // return the ordinal suffix of $value
  $num = $value % 100; // protect against large numbers
  if($num < 11 || $num > 13) { // 11, 12, and 13 are special cases
    switch($num % 10) {
      case 1:
        $suffix = 'st';
        break;
      case 2:
        $suffix = 'nd';
        break;
      case 3:
        $suffix = 'rd';
        break;
      default:
        $suffix = 'th';
        break;
    }
  }
  else {
    $suffix = 'th';
  }
  return $suffix;
}

function showArray($array) {

  echo "<pre>\n";
  var_dump($array);
  echo "</pre>\n";
}

function getGoogleCost ($method) {

  // returns cost in units, which gets applied toward user's Google API quota
  // cost values current as of: January 10, 2020

  if ($method == 'search') {
    // https://developers.google.com/youtube/v3/docs/search/list
    return 100;
  }

}

function getFootnote($which) {

  // returns the text of a particular footnote

  if ($which == 'approxTotal') {
     $text = 'The YouTube Data API returns a value <em>totalResults</em> ';
     $text .= 'which is described in the API documentation as ';
     $text .= '&quot;an approximation and may not represent an exact value&quot;. ';
     $text .= 'This value is provided in the table for reference. ';
     $text .= 'It should <em>approximately</em> equal the actual # of videos ';
     $text .= '(reported in the adjacent column). ';
     $text .= 'If there is a large discrepancy between these two numbers, ';
     $text .= 'there may be videos that YTCA was unable to include in the audit for that channel. ';
     $text .= 'Therefore, the results are likely based on a sample of videos, ';
     $text .= 'rather than the full population.';
  }
  elseif ($which == 'highTraffic') {
    $text = '&quot;High traffic&quot; is any video with views ';
    $text .= 'greater than the mean for that channel.';
  }
  return $text;
}

function getFirstKey($channels) {

  // return the first key of the array $channels
  // channels in ini file must be numbered sequentially
  // but they can start at any number
  // (useful if an audit needs to be restarted halfway through a list of channels)
  foreach($channels as $key => $unused) {
    return $key;
  }
  return NULL;
}

function getLastKey($channels) {

  // return the last key of the array $channels
  end($channels);
  $key = key($channels);
  return $key;
}

function formatDateForYoutube($date) {
  // YYY-MM-DD -> YYYY-MM-DDT00:00:00Z

  return trim($date)."T00:00:00Z";
}
?>
