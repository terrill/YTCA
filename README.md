YouTube Caption Auditor (YTCA)
==============================

*YTCA* is a utility to collect stats from one or more YouTube channels. 
It was designed to collect data related to captioning, but could be extended 
to support other data collection needs.  

Data Collected for each YouTube Channel
---------------------------------------

* Number of videos
* Total duration of all videos
* Number of videos with captions (does not include YouTube's machine-generated captions)
* Percent of videos that are captioned
* Mean number of views per video 
* Number of "high traffic" videos (see High Traffic section below for details)
* Number of high traffic videos that are captioned 
* Percent of high traffic videos that are captioned
* Total duration of captioned videos 
* Total duration of captioned high traffic videos 

High Traffic Videos
-------------------

In order to prioritize captioning efforts, one may wish to focus on videos that are "high traffic". 
By default, YTCA considers a video "high traffic" if its number of views is greater than the 
mean for that channel. 

Alternatively, you can define your own high traffic threshold using filters. 
See the following section for details. 

Filters
-------------------
Filters can be used to narrow the output to "high traffic" videos, defined in various of ways 
using the *filtertype* and *filtervalue* parameters, which can be passed to YTCA via the URL. 
Alternatively default filter settings can be defined in the Configuration block within [ytca.php][].

Supported values of *filtertype* are: 

* views - limit results to videos that have X or more views
* percentile - limit results to videos that fall into the X percentile for the channel based on views (e.g., the top 10%)
* count - limit results to the top X videos for the channel, based on views

A *filtertype* parameter must always be accompanied by a a *filtervalue* parameter, which defines the value of X as used 
by the filter. 

In the following example, videos are limited to those with views greater than 10,000: 

**ytca.php?filtertype=count&filtervalue=10000**


Filtering by Date
-------------------

YTCA can limit results to just those videos published within a particular date range. 
The *date-start* and *date-end* parameters can be used independently or together. 

In the following example, videos are limited to those published on or after January 1, 2016: 

**ytca.php?date-start=2016-01-01**

In the following example, videos are limited to those published between January 1, 2016 and June 30, 2016: 

**ytca.php?date-start=2016-01-01&date-end=2016-06-30**


URL Parameters
-------------------
YTCA supports the following parameters, which can be passed by URL or made permanent as default values 
in the Configuration block within [ytca.php][]. 

* **report** - supported values are 'summary' (default; shows summary statistics for all channels) or 'details' (shows statistics for individual videos)
* **channels** - path to an ini file that identifies all YouTube channels to be included in the analysis. See [channels.ini][] for an example.  
* **channelid** - id of a single YouTube channel. If this parameter is passed, the specified channel will be analyzed instead of channels in the ini file. 
* **output** - format of report; supported values are 'html' (default), 'xml', or 'json'. The latter can be used to generate custom reports or save results to a database (this functionality is not included in the repo).
* **filtertype** - supported values are 'views', 'percentile', or 'count'. See the preceding section for details. 
* **filtervalue** - the value associated with the chosen filter type. See the preceding section for details. 
* **date-start** - limit results to videos published on or after this date (values must be in the form YYYY-MM-DD, e.g., 2016-01-01)
* **date-end** - limit results to videos published on or before this date 
* **timeunit** - the unit in which "duration" values are reported. Supported values are 'seconds' (the default), 'minutes', or 'hours'
* **title** - title of the report. Must be URL-encoded. For example, spaces must be replaced with plus signs (+) and other special characters must be replaced with their UTF-8 codes. See [W3Schools URL Encoding Reference][] for details, including a UTF-8 conversion chart.  
* **show-channel-id** - supported values are 'true' or 'false'. If true, a YouTube Channel ID column is included in the HTML output of summary report. Default is 'false'.
* **debug** - supported values are 'true' or 'false'. If true, includes URLs of all YouTube queries in the output so users can inspect the query and the raw data returned from YouTube in response. Default is 'false'. 


Requirements
------------

* PHP 5.3 or higher 
* A YouTube API key. For more information see the [YouTube Data API Reference][]. 
    

Instructions
------------

1. Define variables in the Configuration block within [ytca.php][].  
2. Run it in a browser. Use the URL parameters listed above to fine-tune the output and explore the data.  

YouTube Channel IDs
-------------------

YouTube channels can be analyzed one channel at a time by passing a **channelid** parameter via the URL. 
Multiple channels can be analyzed in a batch by defining them in an ini file such as [channels.ini][]. 

The YouTube Channel ID is a 24-character string that starts with the characters "UC". 
This sometimes appears in the URL for the channel. 
For example, the URL for the main University of Washington channel is 

**https://www.youtube.com/channel/UCJgq3uJ5jFCbNB06TC9BFBw** 

The channel ID is **UCJgq3uJ5jFCbNB06TC9BFBw**

If the channel URL is not presented in the above form, here's one way to 
find the channel ID for that channel: 

1. View source 
2. Search the source code for the attribute *data-channel-external-id*
3. The value of that attribute is the channel ID 

Alternatively, you can substitute the *user name* for any channel ID.
As long as it's a valid user name and is not a 24-character string starting with "UC", 
YTCA can look up the channel ID without you having to follow the above steps. 

For example, here's an alternative URL for accessing the main University of Washington channel: 

**https://www.youtube.com/user/uwhuskies** 
 
The user name is **uwhuskies** 

YTCA could analyze this one channel using either of the following URLs: 

**ytca.php?channelid=uwhuskies** 

**ytca.php?channelid=UCJgq3uJ5jFCbNB06TC9BFBw** 

Both of these URLs generate the same output, but the former adds overhead because YTCA needs to 
lookup each unknown channel ID via an extra query to the YouTube Data API.  

Output
------

By default, the output from YTCA is one HTML file containing a table of statistics. 
Each row within the table contains summary data for one YouTube channel,  
and summary statistics are provided in a summary row at the bottom of the table. 
 
If **report=details** is passed via the URL, the output contains a separate table 
for each channel, showing the title, number of views, and duration of each video in the channel, 
plus a Yes/No field showing whether each video is captioned. 

About Quotas
------------

The YouTube Data API has a limit on how many API queries can be executed per day. 
For additional details, see [Quota usage][] in the API documentation.

Quotas can be estimated using YouTube's [Quota Calculator][].  

As of June 2015, quota costs associated with running this application are:
* 100 units for each channel (search query) 
* 5 units for each video 
* 5 units for each channel ID lookup from a user name

For example, if you have a daily quota of 5,000,000 units you could run this application 
to collect data from 100 channels (10,000 units) containing 998,000 videos.   
 
 
[YouTube Data API Reference]: https://developers.google.com/youtube/v3/docs/
[Quota Usage]: https://developers.google.com/youtube/v3/getting-started#quota
[Quota Calculator]: https://developers.google.com/youtube/v3/determine_quota_cost
[W3Schools URL Encoding Reference]: https://www.w3schools.com/tags/ref_urlencode.asp
[channels.ini]: channels.ini
[ytca.php]: ytca.php
