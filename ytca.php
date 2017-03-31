<?php
/*
 * YouTube Captions Auditor (YTCA)
 * version 1.0.7
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
// Value is either true or false
// If true, includes YouTube API query URLs in output so user can inspect raw data directly
$debug = false;

// Path to channels ini file (can be overwritten with parameter 'channels' in URL)
$channelsFile = 'channels.ini';

// Output (can be overwritten with parameter 'output' in URL)
// Supported values: html, xml, json (EVENTUALLY; xml and json are not yet supported)
$output = 'html';

// Report (can be overwritten with parameter 'report' in URL)
// Supported values:
//  summary - counts and other stats for each channel
//  details - metadata, traffic data, and caption data for each video in results
$report = 'summary';

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
$title = 'YouTube Caption Auditor (YTCA) Report';

// Time unit for "Duration" data (can be overwritten with 'timeunit' in URL)
// Supported values are 'seconds' (default), 'minutes', or 'hours'
$timeUnit = 'seconds';

// Include Links
// Set to true to make channel names hyperlinks to the YouTube channel; otherwise set to false
$includeLinks = true;

// Include Channel ID
// Set to true to include a YouTube Channel ID column in the output; otherwise false
$includeChannelId = false;

// Highlights
// Optionally, the report can highlight channels that are either doing good or bad at captioning
// To use this feature, set the following variables
// if $highlights['use'] is false, all other variables are ignored
$highlights['use'] = true;
$highlights['goodPct'] = 50; // Percentages >= this value are "good"
$highlights['badPct'] = 0; // Percentages <= this value are "bad"
$highlights['goodColor'] = '#99FF99'; // light green
$highlights['badColor'] = '#FFD7D7'; // light red
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
  // convert to boolean; accept 'true', 'false' or 1 or 0
  if (strtolower($_GET['debug']) == 'true' || $_GET['debug'] == '1') {
    $debug = true;
  }
}
if (isset($_GET['output'])) {
  if (isValid('output',strtolower($_GET['output']))) {
    $output = strtolower($_GET['output']);
  }
}
if (isset($_GET['report'])) {
  if (isValid('report',strtolower($_GET['report']))) {
    $report = strtolower($_GET['report']);
  }
}
$filter = false;
if (isset($_GET['filtertype']) && isset($_GET['filtervalue'])) {
  if (isValid('filterType',strtolower($_GET['filtertype']))) {
    if (isValid('filterValue',$_GET['filtervalue'],strtolower($_GET['filtertype']))) {
      $filter['type'] = strtolower($_GET['filtertype']);
      $filter['value'] = $_GET['filtervalue'];
    }
  }
}
if (isset($_GET['title'])) {
  if (isValid('title',strip_tags($_GET['title']))) {
    $title = urldecode(strip_tags($_GET['title']));
  }
}
if (isset($_GET['timeunit'])) {
  if (isValid('timeUnit',strtolower($_GET['timeunit']))) {
    $timeUnit = strtolower($_GET['timeunit']);
  }
}
if (isset($_GET['channels'])) {
  if (isValid('channels',strip_tags($_GET['channels']))) {
    $channelsFile = urldecode(strip_tags($_GET['channels']));
  }
}

showTop($title,$highlights['goodColor'],$highlights['badColor'],$filter);

// Get channel from URL (channelid and (optionally) channelname)
// if either parameter is included in URL, that channel is audited rather than using channels.ini
if ($channelId = $_GET['channelid']) {
  if (!(ischannelId($channelId))) {
    // this is not a valid channel ID; must be a username
    $channelId = getChannelId($apiKey,$channelId); // returns false if this too fails
  }
  $channels[0]['id'] = $channelId;
  if (isset($_GET['channelname'])) {
    $channels[0]['name'] = $_GET['channelname'];
  }
  else { // use the id for channel name (can be replaced later with channel name from YouTube results)
    $channels[0]['name'] = $channelId;
  }
}
else {
  // get channel(s) from ini file
  $channels = parse_ini_file($channelsFile,true); // TODO: Handle syntax errors in .ini file
}
$numChannels = sizeof($channels);

if ($numChannels > 0) {

  // initialize $totals

  // all videos (count and duration)
  $totals['all']['count'] = 0;
  $totals['all']['duration'] = 0;
  $totals['all']['views'] = 0;
  $totals['all']['maxViews'] = 0;

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

  $channelMeta = getChannelMeta($channels); // return an array if metadata for each channel, else false

  // prepare to write output immediately to screen (rather than wait for script to finish executing)
  if (ob_get_level() == 0) {
    ob_start();
  }

  $c = 0;
  while ($c < $numChannels) {

    if (!(ischannelId($channels[$c]['id']))) {
      // this is not a valid channel ID; must be a username
      $channels[$c]['id'] = getChannelId($apiKey,$channels[$c]['id']);
    }
    if ($report == 'summary') {
      if ($c == 0) {
        $firstChannelName = $channels[0]['name'];
        showSummaryTableTop($report,$numChannels,$firstChannelName,$channelMeta,$includeChannelId,$timeUnit,$filter);
      }
    }
    $channelQuery = buildYouTubeQuery('search',$channels[$c]['id'],$apiKey);
    // create an array of metadata for this channel (if any exists)
    $numKeys = sizeof($channels[$c]);
    if ($numKeys > 2) {
      // there is supplemental meta data in the array
      $keys = array_keys($channels[$c]);
      $i = 0;
      while ($i < $numKeys) {
        $key = $keys[$i];
        if ($key !== 'name' && $key !== 'id') {
          $metaKeys[] = $key;
        }
        $i++;
      }
    }
    if ($debug) {
      echo '<div class="ytca_debug ytca_channel_query">';
      echo '<span class="query_label">Initial channel query:</span>'."\n";
      echo '<span class="query_url"><a href="'.$channelQuery.'">'.$channelQuery."</a></span>\n";
      echo "</div>\n";
    }
    if ($content = fileGetContents($channelQuery)) {
      $json = json_decode($content,true);
      $numVideos = $json['pageInfo']['totalResults'];
      $channel['videoCount'] = $numVideos;
      if ($numVideos > 0) {
        // add a 'videos' key for this channel that point to all videos
        $channels[$c]['videos'] = getVideos($channels[$c]['id'],$json,$numVideos,$apiKey,$debug);
      }
      else {
        // TODO: handle error: No videos returned by $channelQuery
      }
    }
    else {
      // TODO: handle error: Unable to retrieve file: $channelQuery
    }
    if ($filter) {
      $videos = applyFilter($channels[$c]['videos'],$filter);
    }
    else {
      $videos = $channels[$c]['videos'];
    }
    $numVideos = sizeof($videos);
    // add values to channel totals
    $channelData['all']['count'] = $numVideos;
    $channelData['all']['duration'] = calcDuration($videos,$numVideos);
    $viewsData = countViews($videos,$numVideos); // returns array with keys 'count' and 'max'
    $channelData['all']['views'] = $viewsData['count'];
    $channelData['all']['maxViews'] = $viewsData['max'];
    $channelData['cc']['count'] = countCaptioned($videos,$numVideos);
    $channelData['cc']['duration'] = calcDuration($videos,$numVideos,'true');
    if (!$filter) {
      // no reason to separate out high traffic videos if already filtering for high traffic
      $channelData['all']['avgViews'] = round($channelData['all']['views']/$channelData['all']['count']);
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

    if ($report == 'details') {
      echo '<h2>Channel '.$rowNum.' of '.$numChannels.': '.$channels[$c]['name']."</h2>\n";
      showDetailsList($channels[$c]['id'],$channelMeta[$c],$channelData,$timeUnit,$includeLinks,$includeChannelId,$filter);
      showDetailsTable($videos,$numVideos);
    }
    else { // show a summary report
      showSummaryTableRow($report,$rowNum,$numChannels,$channels[$c]['id'],$channels[$c]['name'],$nextChannelName,$channelMeta[$c],$channelData,$timeUnit,$includeLinks,$includeChannelId,$filter,$highlights);

      // increment totals with values from this channel
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
  }

  if ($report == 'summary') {
    // add totals row
    showSummaryTableRow($report,'totals',$numChannels,NULL,NULL,NULL,$channelMeta[0],$totals,$timeUnit,$includeLinks,$includeChannelId,$filter);
    showSummaryTableBottom();
  }
}
else {
  // handle error - no channels were found
}
showBottom();

// stop calculating time of execution and display results
$timeEnd = microtime(true);
$time = round($timeEnd - $timeStart,2); // in seconds
echo '<p class="runTime">Total run time: '.makeTimeReadable($time).'</p>'."\n";

ob_end_flush();

function showTop($title,$goodColor,$badColor,$filter=NULL) {

  echo "<!DOCTYPE html>\n";
  echo "<head>\n";
  echo '<title>'.$title."</title>\n";
  echo '<link rel="stylesheet" type="text/css" href="ytca.css">'."\n";
  echo '<style>'."\n";
  echo "tr.goodChannel th,\n";
  echo "tr.goodChannel td {\n";
  echo "  background-color: $goodColor;\n";
  echo "}\n";
  echo "tr.badChannel th,\n";
  echo "tr.badChannel td {\n";
  echo "  background-color: $badColor;\n";
  echo "}\n";
  echo "</style>\n";
  echo "</head>\n";
  echo '<body id="ytca">'."\n";
  echo '<h1>'.$title."</h1>\n";
  echo '<p class="date">'.date('M d, Y')."</p>\n";
  echo '<div id="status" role="alert"></div>'."\n";
  echo '<script src="ytca.js"></script>'."\n";
  if ($filter) {
    echo '<p class="filterSettings">';
    echo 'Filter on. Including only ';
    if ($filter['type'] == 'views') {
      echo 'videos with <span class="filterValue">'.$filter['value'].'</span> views.';
    }
    elseif ($filter['type'] == 'percentile') {
      echo 'videos in the <span class="filterValue">';
      echo $filter['value'].getOrdinalSuffix($filter['value']);
      echo '</span> percentile for each channel.';
    }
    elseif ($filter['type'] == 'count') {
      echo 'the top <span class="filterValue">'.$filter['value'].'</span> videos in each channel ';
      echo '(based on views)';
    }
    echo "</p>\n";
  }
}

function showSummaryTableTop($report,$numChannels,$firstChannelName,$channelMeta,$includeChannelId,$timeUnit,$filter) {

  // $metaData is an array of 'keys' and 'values' for each channel; or false

  echo '<table id="report">'."\n";
  echo '<thead>'."\n";
  echo '<tr';
  // add a data-status attribute that's used by ytca.js to populate the status message at the top of the page
  // this reflects the *next* channel, since it isn't written to the screen until the channel row is complete
  if ($firstChannelName) {
    echo ' data-status="Processing Channel 1 of '.$numChannels.': '.$firstChannelName.'..."';
  }
  echo '>'."\n";
  echo '<th scope="col">ID</th>'."\n";
  echo '<th scope="col">YouTube Channel</th>'."\n";
  if ($includeChannelId) {
    echo '<th scope="col">YouTube ID</th>'."\n";
  }
  if ($channelMeta) {
    $metaKeys = array_keys($channelMeta[0]); // get keys from first channel in array
    $numMeta = sizeof($metaKeys);
    // there is supplemental meta data
    // display a column header for each metaData key
    $i = 0;
    while ($i < $numMeta) {
      echo '<th scope="col">'.$metaKeys[$i]."</th>\n";
      $i++;
    }
  }
  echo '<th scope="col"># Videos</th>'."\n";
  echo '<th scope="col"># Captioned</th>'."\n";
  echo '<th scope="col">% Captioned</th>'."\n";
  echo '<th scope="col"># '.ucfirst($timeUnit)."</th>\n";
  echo '<th scope="col"># '.ucfirst($timeUnit).' Captioned</th>'."\n";
  echo '<th scope="col">Mean Views per Video</th>'."\n";
  echo '<th scope="col">Max Views</th>'."\n";
  if (!$filter) {
    // no reason to separate out high traffic videos if already filtering for high traffic
    echo '<th scope="col"># Videos High Traffic<sup>*</sup></th>'."\n";
    echo '<th scope="col"># Captioned High Traffic<sup>*</sup></th>'."\n";
    echo '<th scope="col">% Captioned High Traffic<sup>*</sup></th>'."\n";
    echo '<th scope="col"># '.ucfirst($timeUnit).' Captioned High Traffic<sup>*</sup></th>'."\n";
  }
  echo "</tr>\n";
  echo '</thead>'."\n";
  echo '<tbody>'."\n";

  // write output immediatley to screen
  ob_flush();
  flush();
}

function showSummaryTableRow($report,$rowNum,$numChannels,$channelId=NULL,$channelName=NULL,$nextChannelName=NULL,$metaData=NULL,$channelData,$timeUnit,$includeLinks,$includeChannelId,$filter=NULL,$highlights=NULL) {

  // $rowNum is either an integer, or 'totals'

  // calculate percentages and averages
  $pctCaptioned = round($channelData['cc']['count']/$channelData['all']['count'] * 100,1);
  if (!$filter) {
    // high traffic data is only included for non-filtered channels
    $pctCaptionedHighTraffic = round($channelData['ccHighTraffic']['count']/$channelData['highTraffic']['count'] * 100,1);
  }

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

  $numMeta = sizeof($metaData);
  if ($rowNum == 'totals') {
    echo ' class="totals">';
    // calculate colspan for Totals row header
    // always span ID and Name columns
    if ($includeChannelId) { // span that too, plus all metadata columns
      $colSpan = $numMeta + 3;
    }
    else { // span all metadata columns
      $colSpan = $numMeta + 2;
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
    if (is_array($classes) && sizeof($classes) > 0) {
      echo ' class="';
      $i=0;
      while ($i < sizeof($classes)) {
        if ($i > 0) {
          echo ' ';
        }
        echo $classes[$i];
        $i++;
      }
      echo '"';
    }
    echo ">\n";

    echo '<td>'.$rowNum."</td>\n";
    echo '<th scope="row">';
    if ($includeLinks) {
      echo '<a href="https://www.youtube.com/channel/'.$channelId.'">';
    }
    echo $channelName;
    if ($includeLinks) {
      echo '</a>';
    }
    echo "</th>\n";
    if ($includeChannelId) {
      echo '<td>'.$channelId."</td>\n";
    }
    // Display supplemental meta data, if any exists
    if ($metaData) {
      foreach ($metaData as $key => $value) {
        echo '<td>'.$value."</td>\n";
      }
    }
  } // end if not totals row
  echo '<td class="data">'.number_format($channelData['all']['count'])."</td>\n";
  echo '<td class="data">'.number_format($channelData['cc']['count'])."</td>\n";
  echo '<td class="data">'.number_format($pctCaptioned,1)."%</td>\n";
  echo '<td class="data">'.formatDuration($channelData['all']['duration'],$timeUnit)."</td>\n";
  echo '<td class="data">'.formatDuration($channelData['cc']['duration'],$timeUnit)."</td>\n";
  echo '<td class="data">'.number_format($channelData['all']['avgViews'])."</td>\n";
  echo '<td class="data">'.number_format($channelData['all']['maxViews'])."</td>\n";
  if (!$filter) {
    // high traffic data is only included for non-filtered channels
    echo '<td class="data">'.number_format($channelData['highTraffic']['count'])."</td>\n";
    echo '<td class="data">'.number_format($channelData['ccHighTraffic']['count'])."</td>\n";
    echo '<td class="data">'.number_format($pctCaptionedHighTraffic,1)."%</td>\n";
    echo '<td class="data">'.formatDuration($channelData['ccHighTraffic']['duration'],$timeUnit)."</td>\n";
  }
  echo "</tr>\n";

  // write output immediately to screen
  ob_flush();
  flush();
}

function showSummaryTableBottom() {

  echo "</tbody>\n";
  echo "</table>\n";

  echo '<p class="footnote"><sup>*</sup> "High traffic" is any video with views ';
  echo 'greater than the mean for that channel.</p>'."\n";
}

function showBottom() {

  echo "</body>\n";
  echo "</html>";
}

function showDetailsList($channelId,$channelMeta,$channelData,$timeUnit,$includeLinks,$includeChannelId,$filter) {

  // show a list of summary data for channel, in conjunction with the video details table

  // calculate percentages
  $pctCaptioned = round($channelData['cc']['count']/$channelData['all']['count'] * 100,1);

  echo '<ul class="channelDetails">'."\n";
  // link to YouTube channel
  if ($includeLinks && $includeChannelId) {
    $channelLink = 'https://www.youtube.com/channel/'.$channelId;
    echo '<li><a href="'.$channelLink.'">'.$channelLink.'</a></li>'."\n";
  }
  // channel meta data
  $numMeta = sizeof($channelMeta);
  if ($numMeta) {
    foreach ($channelMeta as $key => $value) {
      echo '<li>'.$key.': <span class="value">'.$value."</span></li>\n";
    }
  }
  // Number of videos
  echo '<li>Number of videos: <span class="value">';
  echo number_format($channelData['all']['count']).'</span></li>'."\n";

  // Number / percent captioned
  echo '<li>Number captioned: <span class="value">';
  echo number_format($channelData['cc']['count']).'</span> ';
  echo '(<span class="value">'.number_format($pctCaptioned,1).'%</span>)</li>'."\n";

  // Duration
  echo '<li>Total '.$timeUnit.': <span class="value">';
  echo formatDuration($channelData['all']['duration'],$timeUnit).'</span></li>'."\n";

  // Duration (captioned)
  echo '<li>'.ucfirst($timeUnit).' captioned: <span class="value">';
  echo formatDuration($channelData['cc']['duration'],$timeUnit)."</span></td>\n";

  if (!$filter) {
    // high traffic data is only included for non-filtered channels
    echo '<li>Average views: <span class="value">'.number_format($channelData['all']['avgViews'])."</span></li>\n";
    echo '<li>Number of high traffic videos: <span class="value">';
    echo number_format($channelData['highTraffic']['count'])."</span></li>\n";
    echo '<li>Number captioned (high traffic): <span class="value">';
    echo number_format($channelData['ccHighTraffic']['count']).'</span> ';
    echo '(<span class="value">'.number_format($pctCaptionedHighTraffic,1)."%</span>)</li>\n";
    echo '<li>'.ucfirst($timeUnit).' captioned (high traffic): <span class="value">';
    echo formatDuration($channelData['ccHighTraffic']['duration'],$timeUnit)."</span></li>\n";
  }

  echo "</ul>\n";

  // write output immediately to screen
  ob_flush();
  flush();
}

function showDetailsTable($videos,$numVideos) {

  if ($numVideos) {
    // show table head
    echo '<table class="videoDetails">'."\n";
    echo '<thead>'."\n";
    echo '<tr>'."\n";
    echo '<th scope="col">Video Title</th>'."\n";
    echo '<th scope="col">Duration</th>'."\n";
    echo '<th scope="col">Captioned</th>'."\n";
    echo '<th scope="col">Views</th>'."\n";
    echo "</tr>\n";
    echo "</thead>\n";
    echo '<tbody>'."\n";
    ob_flush();
    flush();

    // videos are already sorted by views DESC via applyFilter()

    $i=0;
    while ($i < $numVideos) {
      echo '<tr>'."\n";
      echo '<td><a href="https://youtu.be/'.$videos[$i]['id'].'">'.$videos[$i]['title']."</a></td>\n";
      echo '<td>'.convertToHMS($videos[$i]['duration'])."</td>\n";
      if ($videos[$i]['captions'] == 'true') {
        echo '<td class="ccYes">Yes</td>'."\n";
      }
      elseif ($videos[$i]['captions'] == 'false') {
        echo '<td class="ccNo">No</td>'."\n";
      }
      echo '<td>'.number_format($videos[$i]['views'])."</td>\n";
      echo "</tr>\n";
      ob_flush();
      flush();
      $i++;
    }
    echo '</tbody>'."\n";
    echo "</table>\n";
  }
  else {
    echo '<p>No videos to show.</p>'."\n";
  }
  ob_flush();
  flush();
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

  $query = buildYouTubeQuery('channels', $userName, $apiKey);
  if ($content = fileGetContents($query)) {
    $json = json_decode($content,true);
    $channelId = $json['items'][0]['id'];
    if (isChannelId($channelId)) {
      return $channelId;
    }
  }
  return false;
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
  while ($i < sizeof($channels)) {
    $keys = array_keys($channels[$i]);
    $numKeys = sizeof($keys);
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
  $numMeta = sizeof($metaKeys);
  if ($numMeta > 0) {
    $i=0;
    while ($i < sizeof($channels)) {
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

function buildYouTubeQuery($which, $id, $apiKey, $nextPageToken=NULL) {

  // $which is either 'search', 'channels', or 'videos'
  // $id is a channel ID for 'search' queries; or a video ID for 'videos' query
  // For 'channels' queries $id is a username

  if ($which == 'search') {
    // Cost = 100 units
    $request = 'https://www.googleapis.com/youtube/v3/search?';
    $request .= 'key='.$apiKey;
    $request .= '&channelId='.$id;
    $request .= '&part=id,snippet';
    $request .= '&order=viewcount';
    $request .= '&maxResults=50';
    if ($nextPageToken) {
      $request .= '&pageToken='.$nextPageToken;
    }
  }
  elseif ($which == 'channels') {
    // Cost = 5 units
    // Cheaper than search, but doesn't include individual video data (not even ids)
    // This is currently only used for looking up channel IDs or names
    $request = 'https://www.googleapis.com/youtube/v3/channels?';
    $request .= 'key='.$apiKey;
    $request .= '&forUsername='.$id;
    // $request .= '&id='.$id;
    $request .= '&part=id';
    $request .= '&maxResults=1';
  }
  elseif ($which == 'videos') {
    // Cost = 5 units
    $request = 'https://www.googleapis.com/youtube/v3/videos?';
    $request .= 'key='.$apiKey;
    $request .= '&id='.$id;
    $request .= '&part=contentDetails,statistics';
    $request .= '&maxResults=1';
  }
  return $request;
}

function getVideos($channelId,$json,$numVideos,$apiKey,$debug) {

  $maxResults = 50; // as defined by YouTube API
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
      $nextPageToken = $json['nextPageToken'];
      $channelQuery = buildYouTubeQuery('search',$channelId,$apiKey,$nextPageToken);
      if ($debug) {
        echo '<div class="ytca_debug ytca_channel_query">';
        echo '<span class="query_label">Channel query #'.$q.' of '.$numQueries.':</span>'."\n";
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
      $videoId = $json['items'][$i]['id']['videoId'];
      if ($videoId) {
      // get details about this video via a 'videos' query
        $videoQuery = buildYouTubeQuery('videos', $videoId, $apiKey);
        if ($debug) {
          echo '<div class="ytca_debug">';
          echo '<span class="query_label">Video query #'.$i.' of '.$finalIndex.':</span>'."\n";
          echo '<span class="query_url"><a href="'.$videoQuery.'">'.$videoQuery."</a></span>\n";
          echo "</div>\n";
        }
        if ($videoContent = fileGetContents($videoQuery)) {
          $videos[$v]['id'] = $videoId;
          $videos[$v]['title'] = $json['items'][$i]['snippet']['title'];
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
  return $videos;
}

function applyFilter($videos,$filter) {

  // $videos is an array of video data
  // $filter is an array with 'type' and 'value'
  // All filters are based on views
  // First, must sort $videos array by views DESC
  $numVideos = sizeof($videos);

  if ($filter['type'] == 'views') {
    // include only videos with X views
    $i=0;
    while ($i < $numVideos) {
      if ($videos[$i]['views'] >= $filter['value']) {
        $v[] = $videos[$i];
      }
      $i++;
    }
    return $v;
  }
  elseif ($filter['type'] == 'percentile') {
    // include only videos in the Xth percentile (based on views)
    $videos = sortVideosByViews($videos,SORT_DESC);
    // videos are sorted DESC (not ASC) because that's how we want to display them in the output
    $percentile = $filter['value'];
    $targetIndex = floor(($percentile/100) * $numVideos);
    $i = 0;
    while ($i <= $targetIndex) {
      $v[] = $videos[$i];
      $i++;
    }
    return $v;
  }
  elseif ($filter['type'] == 'count') {
    // include only videos in the Top X (based on views)
    if ($filter['value'] < $numVideos) {
      $videos = sortVideosByViews($videos,SORT_DESC);
      $i=0;
      while ($i < $filter['value']) {
        $v[] = $videos[$i];
        $i++;
      }
      return $v;
    }
    else {
      // there are fewer than X videos in this channel.
      // Return all videos
      return $videos;
    }
  }
}

function sortVideosByViews($videos,$sort) {

  // return $videos array sorted by views
  // $sort is either SORT_ASC or SORT_DESC
  foreach ($videos as $key=>$row) {
    $views[$key] = $row['views'];
  }
  array_multisort($views, $sort, $videos);
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
    if ($duration) {
      $seconds += convertToSeconds($duration);
    }
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
  $interval = new DateInterval($duration);
  return ($interval->h*3600)+($interval->i*60)+($interval->s);
}

function convertToHMS($duration) {

  // see comments above about $duration dormat
  // convert to HH:MM:SS
  $interval = new DateInterval($duration);
  $hours = sprintf("%02d",$interval->h);
  $minutes = sprintf("%02d",$interval->i);
  $seconds = sprintf("%02d",$interval->s);
  return $hours.':'.$minutes.':'.$seconds;
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
?>