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
* Total duration of uncaptioned videos 
* Total duration of uncaptioned high traffic videos 

High Traffic Videos
-------------------

In order to prioritize captioning efforts, it can be focus on videos that are "high traffic". 
By default, YTCA considers a video "high traffic" if its number of views is greater than the 
mean for that channel. 

Alternatively, you can define your own high traffic threshold using the designated 
variable in the Configuration block within [ytca.php][]. If this variable is greater than 0, 
YTCA uses that as the high traffic threshold instead of the mean number of views.  

Requirements
------------

* PHP 5.3 or higher 
* A YouTube API key. For more information see the [YouTube Data API Reference][]. 
    

Instructions
------------

1. Define variables in the Configuration block within [ytca.php][].  
2. Run it. 
3. Each channel will be processed individually. After each channel is processed, click the green button at the bottom of the page to proceed with the next channel. 

Additional documentation is provided within the comments in [ytca.php][]. 

YouTube Channel IDs
-------------------

The Configuration block includes an array of YouTube Channel IDs. 
The YouTube Channel ID is a 24-character string, which sometimes appears in the URL 
for the channel. For example, the URL for the main University of Washington channel is 

**https://www.youtube.com/channel/UCJgq3uJ5jFCbNB06TC9BFBw** 

The channel ID is **UCJgq3uJ5jFCbNB06TC9BFBw**

If the channel URL is not presented in the above form, here's one way to 
find the channel ID for that channel: 

1. View source 
2. Search the source code for the attribute *data-channel-external-id*
3. The value of that attribute is the channel ID 

Output
------

By default, the output from YTCA is one HTML file containing a table of statistics. 
Each row within the table contains summary data for one YouTube channel,  
and summary statistics are provided in a summary row at the bottom of the table. 
 
Alternatively, you could customize YTCA by saving the data collected to a database, 
then perform subsequent analyses on the data. 

About Quotas
------------

The YouTube Data API has a limit on how many API queries can be executed per day. 
For additional details, see [Quota usage][] in the API documentation.

Quotas can be estimated using YouTube's [Quota Calculator][].  

As of June 2015, quota costs associated with running this application are:
* 100 units for each channel (search query) 
* 5 units for each video 

For example, if you have a daily quota of 5,000,000 units you could run this application 
to collect data from 100 channels (10,000 units) containing 998,000 videos.   
 
 
[YouTube Data API Reference]: https://developers.google.com/youtube/v3/docs/
[Quota Usage]: https://developers.google.com/youtube/v3/getting-started#quota
[Quota Calculator]: https://developers.google.com/youtube/v3/determine_quota_cost
[ytca.php]: ytca.php
