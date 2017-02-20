<?php
/*
 * YouTube Captions Auditor (YTCA)
 * version 1.0.2
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

$apiKey = file_get_content($apiKeyFile);

// Title of report
$title = 'YouTube Caption Auditor (YTCA) Report';

// To include only relatively high traffic videos:
// 1. set $includeHighTraffic to true; set to false to skip this analysis
// 2. set $minViews to a positive integer
// If $minViews = 0, YTCA uses the mean # of views per channel as a traffic threshold
// Analyzing high traffic videos separately can help with prioritization if it isn't feasible to caption everything
$includeHighTraffic = true;
$minViews = 0;

// Optionally, the report can highlight channels that are either doing good or bad at captioning
// To use this feature, set the following variables
// if $includeHighlights = false, all other variables are ignored
$includeHighlights = true;
$goodPct = 50; // Percentages >= this value are "good"
$badPct = 0; // Percentages <= this value are "bad"
$goodColor = '#99FF99'; // light green
$badColor = '#FFD7D7'; // light red
// labels are added as a title attribute for the channel name
$goodLabel = 'Exemplary channel';
$badLabel = 'Needs work';

// Set $includeLinks to true to make channel names hyperlinks to the YouTube channel; otherwise set to false
$includeLinks = true;

// Set $includeChannelID to true to include a YouTube Channel ID column in the output; otherwise false
$includeChannelID = false;

// Copy and uncomment the following line for each YouTube channel. Assign 'name' and 'id' as follows:
// 'name' - The name of the channel as you would like it to appear in the report
// 'id' - either the 24-character YouTube Channel ID or its associated username (see README.md for more about channel IDs)
// $channels[] = array('name'=>'Name of Channel','id'=>'channel_id');

// Optionally, the $channels array can include additional keys.
// Any keys other than 'name' and 'id' will be used as column headers in the report
// and their values will be displayed as data for that channel in the report
// This can be useful if there is additional known meta data that you would like to include in the report
// Example:
// $channels[] = array('name'=>'Bernie Sanders','id'=>'UCH1dpzjCEiGAt8CXkryhkZg','Party'=>'Democrat');

/***********************
 *                     *
 *  END CONFIGURATION  *
 *                     *
 ***********************/

error_reporting(E_ERROR | E_PARSE);
ini_set(max_execution_time,900); // in seconds; increase if script is timing out on large channels

showTop($title,$goodColor,$badColor);
$timeStart = microtime(true); // for benchmarking
$numChannels = sizeof($channels);

// It can take a long time to collect data for all videos within a channel
// Therefore this script only handles one channel at a time.
if ($numChannels > 0) {
  if (!($c = $_POST['channel'])) {
    $c = 0;
  }
  $channelId = $channels[$c]['id'];
  if (!(ischannelId($channelId))) {
    // this is not a valid channel ID; must be a username
    $channelId = getChannelId($apiKey,$channelId);
  }
  $channelQuery = buildYouTubeQuery('search',$channelId,$apiKey);
  $channel['name'] = $channels[$c]['name'];
  $channel['id'] = $channelId;
  $numKeys = sizeof($channels[0]);
  if ($numKeys > 2) {
    // there is supplemental meta data in the array
    $keys = array_keys($channels[0]);
    $i = 0;
    while ($i < $numKeys) {
      $key = $keys[$i];
      if ($key !== 'name' && $key !== 'id') {
        $metaKeys[] = $key;
      }
      $channel[$key] = $channels[$c][$key];
      $i++;
    }
  }
  if ($content = fileGetContents($channelQuery)) {
    $json = json_decode($content,true);
    $numVideos = $json['pageInfo']['totalResults'];
    $channel['videoCount'] = $numVideos;
    if ($numVideos > 0) {
      $channel['videos'] = getVideos($channelId,$json,$numVideos,$apiKey);
    }
    else {
      echo 'URL Error: '.$channelQuery."<br/>\n";
    }
    $i++;
  }
  else {
    echo '<p class="error">Unable to retrieve file: ';
    echo '<a href="'.$channelQuery.'">'.$channelQuery.'</a></p>'."\n";
  }
  showResults($c,$channel,$channels,$numChannels,$metaKeys,$includeHighlights,$goodPct,$badPct,$goodLabel,$badLabel,$includeHighTraffic,$minViews,$includeLinks,$includechannelId);
}
else {
  echo 'There are no channels.<br/>';
}

// end benchmark and report result
$timeEnd = microtime(true);
$time = $timeEnd - $timeStart;
if ($c < ($numChannels-1)) {
  // run time is only meaningful per channel.
  // Don't show this in the final report.
  echo '<p>Total run time: '.$time.' seconds</p>'."\n";
}
echo "</body>\n</html>";

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
    // This is currently only used for looking up channel IDs
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

function getVideos($channelId,$json,$numVideos,$apiKey) {

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
      if ($content = fileGetContents($channelQuery)) {
        $json = json_decode($content,true);
      }
    }

    // now step through each item in the search query results, collecting data about each
    $i=0;
    while ($i < $finalIndex) {
      $videoId = $json['items'][$i]['id']['videoId'];
      $videos[$v]['id'] = $videoId;
      $videos[$v]['title'] = $json['items'][$i]['snippet']['title'];
      // now get additional data about this video via a 'videos' query
      $videoQuery = buildYouTubeQuery('videos', $videoId, $apiKey);
      $videos[$v]['query'] = $videoQuery; // added for debugging purposes
      if ($videoContent = fileGetContents($videoQuery)) {
        $videos[$v]['status'] = 'Success!'; // added for debugging purposes
        $videoJson = json_decode($videoContent,true);
        $videos[$v]['duration'] = $videoJson['items'][0]['contentDetails']['duration'];
        $videos[$v]['captions'] = $videoJson['items'][0]['contentDetails']['caption']; // 'true' or 'false'
        $videos[$v]['views'] = $videoJson['items'][0]['statistics']['viewCount'];
      }
      $v++;
      $i++;
    }
    $q++;
  }
  return $videos;
}

function showResults($c,$channel,$channels,$numChannels,$metaKeys,$includeHighlights,$goodPct,$badPct,$goodLabel,$badLabel,$includeHighTraffic,$minViews,$includeLinks,$includechannelId) {

  if ($numChannels > 0) {
    echo "<table>\n";
    echo "<tr>\n";
    echo '<th scope="col">ID</th>'."\n";
    echo '<th scope="col">YouTube Channel</th>'."\n";
    if ($includechannelId) {
      echo '<th scope="col">YouTube ID</th>'."\n";
    }
    $numMeta = sizeof($metaKeys);
    if ($numMeta > 0) {
      // there is supplemental meta data in the array
      // display a column header for each meta data key
      $i = 0;
      while ($i < $numMeta) {
        echo '<th scope="col">'.$metaKeys[$i]."</th>\n";
        $i++;
      }
    }
    echo '<th scope="col"># Videos</th>'."\n";
    echo '<th scope="col">Duration</th>'."\n";
    echo '<th scope="col"># Captioned</th>'."\n";
    echo '<th scope="col">% Captioned</th>'."\n";
    echo '<th scope="col">Mean # Views per Video</th>'."\n";
    if ($includeHighTraffic) {
      echo '<th scope="col"># Videos High Traffic</th>'."\n";
      echo '<th scope="col"># Captioned High Traffic</th>'."\n";
      echo '<th scope="col">% Captioned High Traffic</th>'."\n";
    }
    echo '<th scope="col">Duration Uncaptioned</th>'."\n";
    if ($includeHighTraffic) {
      echo '<th scope="col">Duration Uncaptioned High Traffic</th>'."\n";
    }
    echo "</tr>\n";
    if ($resultsField = $_POST['results']) {
      // this is not the first channel processed
      // $results contains all previous data as comma-delimited text
      $results = explode("\n",$resultsField);
      $numResults = sizeof($results);
      if ($numResults > 0) {
        // populate table with previous results
        $i=0;
        $totalVideos = 0;
        $totalDuration = 0;
        $totalCaptioned = 0;
        $totalDurationUncaptioned = 0;
        if ($includeHighTraffic) {
          $totalVideosHighTraffic = 0;
          $totalCaptionedHighTraffic = 0;
          $totalDurationUncaptionedHighTraffic = 0;
        }

        while ($i < $numResults) {
          if (strlen($results[$i])>0) {
            $resultsData = str_getcsv($results[$i]);
            echo '<tr';
            if ($includeHighlights) {
              if ($resultsData[6] >= $goodPct) {
                echo ' class="goodChannel">'."\n";
                $channelTitle = ' title="'.$goodLabel.'"';
              }
              elseif ($resultsData[6] <= $badPct) {
                echo ' class="badChannel">'."\n";
                $channelTitle = ' title="'.$badLabel.'"';
              }
              else {
                echo '>'."\n";
                $channelTitle = NULL;
              }
            }
            else {
              echo '>'."\n";
              $channelTitle = NULL;
            }
            echo '<td>'.$resultsData[0]."</td>\n"; // column number
            echo '<td';
            if ($channelTitle) {
              echo $channelTitle;
            }
            echo '>';
            if ($includeLinks) {
              echo '<a href="https://www.youtube.com/channel/'.$resultsData[2].'">'; // channel name
            }
            echo $resultsData[1];
            if ($includeLinks) {
              echo '</a>';
            }
            echo "</td>\n";
            if ($includechannelId) {
              echo '<td>'.$resultsData[2]."</td>\n"; // channel id
            }
            // If channel included supplemental meta data, it was stored at end of $resultsData
            if ($numMeta) {
              if ($includeHighTraffic) {
                $metaIndex = 13;
              }
              else {
                $metaIndex = 9;
              }
              $j = 0;
              while ($j < $numMeta) {
                echo '<td>'.$resultsData[$metaIndex]."</td>\n";
                $metaIndex++;
                $j++;
              }
            }
            echo '<td class="data">'.number_format($resultsData[3])."</td>\n"; // number of videos
            $totalVideos += $resultsData[3];
            echo '<td class="data">'.number_format($resultsData[4])."</td>\n"; // duration
            $totalDuration += $resultsData[4];
            echo '<td class="data">'.number_format($resultsData[5])."</td>\n"; // number captioned
            $totalCaptioned += $resultsData[5];
            echo '<td class="data">'.number_format($resultsData[6],1)."%</td>\n"; // percent captioned
            echo '<td class="data">'.number_format($resultsData[7])."</td>\n"; // mean # of views
            if ($includeHighTraffic) {
              echo '<td class="data">'.number_format($resultsData[8])."</td>\n"; // # videos high traffic
              $totalVideosHighTraffic += $resultsData[8];
              echo '<td class="data">'.number_format($resultsData[9])."</td>\n"; // # captioned high traffic
              $totalCaptionedHighTraffic += $resultsData[9];
              echo '<td class="data">'.number_format($resultsData[10],1)."%</td>\n"; // % captioned high traffic
              echo '<td class="data">'.number_format($resultsData[11])."</td>\n"; // duration uncaptioned
              $totalDurationUncaptioned += $resultsData[11];
              echo '<td class="data">'.number_format($resultsData[12])."</td>\n"; // duration uncaptioned high traffic
              $totalDurationUncaptionedHighTraffic += $resultsData[12];
            }
            else {
              echo '<td class="data">'.number_format($resultsData[8])."</td>\n"; // duration uncaptioned
              $totalDurationUncaptioned += $resultsData[8];
            }
            echo "</tr>\n";
          }
          $i++;
        }
      }
    }
    // perform calculations for current channel
    $videos = $channel['videos'];
    $numVideos = sizeof($videos);
    $totalVideos += $numVideos;
    $duration = calcDuration($videos,$numVideos);
    $totalDuration += $duration;
    $numCaptioned = countCaptioned($videos,$numVideos);
    $totalCaptioned += $numCaptioned;
    $pctCaptioned = round($numCaptioned/$numVideos * 100,1);
    $durationUncaptioned = calcDuration($videos,$numVideos,'false');
    $totalDurationUncaptioned += $durationUncaptioned;
    if ($includeHighTraffic) {
      $totalDurationUncaptionedHighTraffic += $durationUncaptionedHighTraffic;
    }
    $avgViews = calcAvgViews($videos,$numVideos); // returns an integer (don't need high precision)
    if ($includeHighTraffic) {
      if ($minViews > 0) {
        $highTrafficThreshold = $minViews;
      }
      else {
        $highTrafficThreshold = $avgViews;
      }
      $numVideosHighTraffic = countHighTraffic($videos,$numVideos,$highTrafficThreshold);
      $totalVideosHighTraffic += $numVideosHighTraffic;
      $numCaptionedHighTraffic = countCaptioned($videos,$numVideos,$highTrafficThreshold);
      $totalCaptionedHighTraffic += $numCaptionedHighTraffic;
      $pctCaptionedHighTraffic = round($numCaptionedHighTraffic/$numVideosHighTraffic * 100,1);
      $durationUncaptionedHighTraffic = calcDuration($videos,$numVideos,'false',$highTrafficThreshold);
      $totalDurationUncaptionedHighTraffic += $durationUncaptionedHighTraffic;
    }

    // add current channel's data to the table
    echo '<tr';
    if ($includeHighlights) {
      if ($pctCaptioned >= $goodPct) {
        echo ' class="goodChannel">'."\n";
        $channelTitle = ' title="'.$goodLabel.'"';
      }
      elseif ($pctCaptioned <= $badPct) {
        echo ' class="badChannel">'."\n";
        $channelTitle = ' title="'.$badLabel.'"';
      }
      else {
        echo '>'."\n";
        $channelTitle = NULL;
      }
    }
    else {
      echo '>'."\n";
      $channelTitle = NULL;
    }
    if ($numResults) {
      echo '<td>'.$numResults."</td>\n";
    }
    else { // this is the first channel
      echo '<td>1</td>'."\n";
    }
    echo '<td';
    if ($channelTitle) {
      echo $channelTitle;
    }
    echo '>';
    if ($includeLinks) {
      echo '<a href="https://www.youtube.com/channel/'.$channel['id'].'">';
    }
    echo $channel['name'];
    if ($includeLinks) {
      echo '</a>';
    }
    echo "</td>\n";
    if ($includechannelId) {
      echo '<td>'.$channel['id']."</td>\n";
    }
    // Display supplemental meta data, if any exists in $channels array
    if ($numMeta) {
      $i = 0;
      while ($i < $numMeta) {
        $key = $metaKeys[$i];
        echo '<td>'.$channel[$key].'</td>'."\n";
        $i++;
      }
    }
    echo '<td class="data">'.number_format($numVideos)."</td>\n";
    echo '<td class="data">'.number_format($duration)."</td>\n";
    echo '<td class="data">'.number_format($numCaptioned)."</td>\n";
    echo '<td class="data">'.$pctCaptioned."%</td>\n";
    echo '<td class="data">'.number_format($avgViews)."</td>\n";
    if ($includeHighTraffic) {
      echo '<td class="data">'.number_format($numVideosHighTraffic)."</td>\n";
      echo '<td class="data">'.number_format($numCaptionedHighTraffic)."</td>\n";
      echo '<td class="data">'.number_format($pctCaptionedHighTraffic,1)."%</td>\n";
    }
    echo '<td class="data">'.number_format($durationUncaptioned)."</td>\n";
    if ($includeHighTraffic) {
      echo '<td class="data">'.number_format($durationUncaptionedHighTraffic)."</td>\n";
    }
    echo "</tr>\n";

    // add a totals row
    echo '<tr class="totals">'."\n";
    echo '<th scope="row" ';
    if ($includechannelId) {
      $colSpan = 3 + $numMeta;
    }
    else {
      $colSpan = 2 + $numMeta;
    }
    echo 'colspan="'.$colSpan.'">TOTALS</th>'."\n";
    echo '<td class="data">'.number_format($totalVideos)."</td>\n";
    echo '<td class="data">'.number_format($totalDuration)."</td>\n";
    echo '<td class="data">'.number_format($totalCaptioned)."</td>\n";
    $totalPctCaptioned = round($totalCaptioned/$totalVideos * 100,1);
    echo '<td class="data">'.$totalPctCaptioned."%</td>\n";
    echo '<td class="data">--</td>'."\n"; // avg is only calculated per channel; no "total avg" needed
    if ($includeHighTraffic) {
      echo '<td class="data">'.number_format($totalVideosHighTraffic)."</td>\n";
      echo '<td class="data">'.number_format($totalCaptionedHighTraffic)."</td>\n";
      $totalPctCaptionedHighTraffic = round($totalCaptionedHighTraffic/$totalVideosHighTraffic * 100,1);
      echo '<td class="data">'.$totalPctCaptionedHighTraffic."%</td>\n";
    }
    echo '<td class="data">'.number_format($totalDurationUncaptioned)."</td>\n";
    if ($includeHighTraffic) {
      echo '<td class="data">'.number_format($totalDurationUncaptionedHighTraffic)."</td>\n";
    }
    echo "</tr>\n";
    echo "</table>\n";

    if ($includeHighTraffic) {
      echo '<p class="footnote">&quot;<em>High traffic</em>&quot; = greater than ';
      if ($minViews > 0) {
        echo $minViews.' views</p>'."\n";
      }
      else {
        echo 'the mean number of views for this channel</p>'."\n";
      }
    }

    if ($c < ($numChannels - 1)) {
      // this is not the last channel
      // append result to the cumulative results field
      echo '<form action="#" method="POST">'."\n";
      echo '<textarea name="results">'."\n";
      echo $resultsField; // data from previous post
      $rowNum = $c + 1;
      echo $rowNum.',';
      echo '"'.addslashes($channel['name']).'",';
      echo '"'.addslashes($channel['id']).'",';
      echo $numVideos.',';
      echo $duration.',';
      echo $numCaptioned.',';
      echo $pctCaptioned.',';
      echo $avgViews.',';
      if ($includeHighTraffic) {
        echo $numVideosHighTraffic.',';
        echo $numCaptionedHighTraffic.',';
        echo $pctCaptionedHighTraffic.',';
      }
      echo $durationUncaptioned;
      if ($includeHighTraffic) {
        echo ','.$durationUncaptionedHighTraffic;
      }
      // Add supplemental meta data to end, since the number of fields is unknown
      // (makes for easier retrieval)
      if ($numMeta) {
        $i = 0;
        while ($i < $numMeta) {
          echo ',';
          $key = $metaKeys[$i];
          echo '"'.$channel[$key].'"';
          $i++;
        }
      }
      echo "\n</textarea>\n";

      $nextIndex = $c+1;
      $nextColNum = $nextIndex+1;
      $nextChannel = 'Proceed to Channel '.$nextColNum.' of '.$numChannels.' (';
      $nextChannel .= $channels[$nextIndex]['name'].')';
      echo '<input type="hidden" name="channel" value="'.$nextIndex.'">'."\n";

      echo '<input type="submit" value="'.$nextChannel.'">'."\n";
      echo "</form>\n";
    }
    else {
      // this *is* the last channel!
      // could display a Success! message
    }
  }
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

function calcAvgViews($videos,$numVideos) {

  // returns average number of views per video
  $i=0;
  $totalViews=0;
  while ($i < $numVideos) {
    $totalViews += $videos[$i]['views'];
    $i++;
  }
  return round($totalViews/$numVideos);
}

function countHighTraffic ($videos,$numVideos,$threshold) {

  // returns average number of views per video
  // $threshold is the number of views above which a video is considered "high traffic"
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

function showTop($title,$goodColor,$badColor) {

  echo "<!DOCTYPE html>\n";
  echo "<head>\n";
  echo '<title>'.$title."</title>\n";
  echo "<style>\n";
  echo <<<END
    body {
      font-family: Arial, sans-serif;
      font-size: 1em;
      margin: 1em;
    }
    .date {
      font-weight: bold;
      font-size: 1.1em;
    }
    table {
      background-color: black;
    }
    th, td {
      background-color: white;
      margin: 1px;
      color: black;
      padding: 1em;
    }
    th[scope="col"] {
      vertical-align: bottom;
    }
    td.data {
      text-align: right;
    }
    td a {
      color: #474747;
      text-decoration: underline;
    }
    tr.goodChannel th,
    tr.goodChannel td {
      background-color: $goodColor;
    }
    tr.badChannel th,
    tr.badChannel td {
      background-color: $badColor;
    }
    tr.totals td {
      font-weight: bold;
    }
    input[type="submit"] {
      margin: 1em;
      font-size: 1em;
      border: 1px solid #666;
      border-radius: 5px;
      padding: 0.15em;
      background-color:#9F9
    }
    input[type="submit"]:hover,
    input[type="submit"]:focus,
    input[type="submit"]:active {
      background-color: black;
      border-color: #9F9;
      color: white;
    }
    textarea {
      display: none;
    }
    p.error {
      font-weight: bold;
    }
    p.footnote {
      margin-left: 2em;
    }
END;
  echo "</style>\n";
  echo "</head>\n";
  echo "<body>\n";
  echo '<h1>'.$title."</h1>\n";
  echo '<p class="date">'.date('M d, Y')."</p>\n";
}

function showArray($array) {

  echo "<pre>\n";
  var_dump($array);
  echo "</pre>\n";
}
?>