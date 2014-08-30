<?php
//
// TODO
// Clean up the D.R.Y. all over (around curl_exec)

set_time_limit(0); // infinity
define('LD_DEBUG', false);
define('LD_CONFIG_FILE', dirname(__FILE__) . '/config.php');
define('LD_COOKIEJAR', dirname(__FILE__).'/cookie.jar');
define('LD_CLI', true);
define('LD_NL', LD_CLI ? PHP_EOL : '<br>');

if (!file_exists(LD_CONFIG_FILE)) die('No config file found.'.PHP_EOL);

// Kill the cookie jar
//if (file_exists(LD_CONFIG_FILE)) unlink(LD_COOKIEJAR);

require LD_CONFIG_FILE;

if (!file_exists($directory)) die('Configured directory does not exist, check config file'.PHP_EOL);

p('App Start');

$ch = curl_init();
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.117 Safari/537.36');
curl_setopt($ch, CURLOPT_HEADER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); // no timeout for connection
curl_setopt($ch, CURLOPT_TIMEOUT, 0); // no timeout for curl
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_COOKIEJAR, LD_COOKIEJAR);
curl_setopt($ch, CURLOPT_COOKIEFILE, LD_COOKIEJAR);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

p('Pruning casts list based on output directory');
$glob = glob($directory . '/*.mp4');
$existing = array();
if ($glob) {
  foreach ($glob as $file) {
    $matches = array();
    if (preg_match('!' . $directory . '/[0-9]+_(.*).mp4!', $file, $matches)) {
      $existing[] = $matches[1];
    }
  }
} else {
  p('Not downloads found');
}
$authed = false;
if (file_exists(LD_COOKIEJAR)) {
  p('Checking if our previous session is still active ... ');
  $authed = check_session($email);
}

if (!$authed) {
  p('Authenticating .. '); 
  $session = do_auth($email, $password);
  if ($session===false) die('failed to auth, dying'.PHP_EOL);
  p('We are authenticated!');
}

p('Grab list from /all');
$casts = grab_all();
if ($casts === false) die('Failed to grab all casts'.PHP_EOL);

// Find existing casts and unset them
// this is a bit ghetto, but who has the time
$casts_tmp = array();
$pre_count = count($casts);
foreach($casts as $cast) {
  $cast_normalized = str_replace(array('series/', 'lessons/'), array('', ''), $cast);
  $cast_normalized = str_replace('/', '_', $cast_normalized);
  if (!in_array($cast_normalized, $existing)) {
    $casts_tmp[] = $cast;
  }
}
$casts = $casts_tmp;
$count = count($casts);
if ($pre_count != $count) {
  p('Already downloaded ' . ($pre_count - $count) . ' casts');
}
if (!$count) {
  p('Nothing new to download, dying');
  die;
}
p('Found ' . $count . ' casts');

$padding = 4;
foreach ($casts as $cast) {
  echo LD_NL . LD_NL;
  $video = get_video_id($cast);
  if (substr_count($video, '?')) {
    $video_id = substr($video, 0, strpos($video, '?'));
  } else {
    $video_id = $video;
  }
  if ($video_id === false) {
    p('Failed to get video id for cast=' . $cast);
    continue;
  }
  $out_name = str_replace(array('series/', 'lessons/'), array('', ''), $cast);
  $out_file = $directory . '/' . str_pad($video_id, $padding, '0', STR_PAD_LEFT) . '_' . str_replace('/', '_', $out_name) . '.mp4';
  if (file_exists($out_file)) {
    p('File exists for out_file=' . $out_file . ', skipping');
    continue;
  }
  p('Checking first url before redirection : https://laracasts.com/downloads/' . $video);
  $url = get_download_url('https://laracasts.com/downloads/' . $video);
  if ($url === false) {
    p('Could not get download url for video_id=' . $video_id);
    continue;
  }

  // Cleanup leading slashes in url
  if ( substr ( $url, 0, 2 ) == "//" )
  {
    $url = substr ( $url, 2, strlen( $url ) - 2 );
  }	

  p('Downloading ' . $url . ' to ' . $out_file);
  $result = download_from_vimeo($out_file, $url);
  if ($result===false) {
    p('Failed to get url=' . $url);
    continue;
  }
  p('Success, got video_id=' . $video_id . ', cast=' . $cast);
}

//functions

/*
 * Authenticates by first call /login, we need to establish
 * cookie jar for session
 */
function do_auth($email, $password) {
  global $ch;
  // init curl
  // Call login to start session
  p('Calling /login', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/login');
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  p(' success');

  // Post login
  p('Posting to /session', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/sessions');
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('email' => $email, 'password' => $password, 'remember' => 'on')));
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  if (preg_match('!^HTTP/1.1 302 Found!', $response)) {
    p(' success, got 302 from /sessions, looking for Location');
    $matches = array();
    if (preg_match('/^Location: (.*)/', $response, $matches)) {
      $Location = $matches[1];
      p('Success, got location=' . $location);
      curl_setopt($ch, CURLOPT_POST, 0);
      curl_setopt($ch, CURLOPT_POSTFIELDS, null);
      return true;
    }
  } else {
    p('failed to get 302 response from /sessions');
    dump_response_to_file($response);
  }
}
/*
 * We have a cookie jar file, so check if our session
 * is still valid by loading /admin/profile
 * we are looking for email because username may
 * not be 100% accurate
 */
function check_session($email) {
  global $ch;
  p('Calling /admin/account ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/admin/account');
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  p(' success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 200 OK!', $response)) {
    p('Found 200 OK, checking for our email, in profile form');
    if (preg_match('/value="' . $email . '"/', $response)) {
      p('Found it, we are still active');
      return true;
    }
  }
}
/*
 * Call /all and get a list of the casts
 */
function grab_all() {
  global $ch;
  p('Calling /all ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/all');
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  p(' success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 200 OK!', $response)) {
    p('Found 200 OK, checking cast links');
    $matches=array();
    $yup = preg_match_all('!https://laracasts.com/(lessons/[^"]+)!', $response, $matches);
    $yup2 = preg_match_all('!https://laracasts.com/(series/[^"]+)!', $response, $matches2);

    if ($yup || $yup2) {
      $casts=array();
      foreach($matches[1] as $i => $match) {
        if ($match<>'lessons/complete' && substr_count($match, '/save')<1) $casts[]=$match;
      }
      foreach($matches2[1] as $i => $match) {
        if (strpos($match, 'episodes')!==false) $casts[]=$match;
      }
      $count=count($casts);
      if (!$count) {
        p('Zero casts found');
        return false;
      }
      p('Found ' . $count . ' casts');
      return $casts;
    }
  }
  return false;
}
/*
 * Get download url from /lessons/ or /series/
 */
function get_video_id($cast) {
  global $ch;
  p('Calling ' . $cast . ' ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/' . $cast);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  p(' success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 200 OK!', $response)) {
    p('Found 200 OK, checking cast links');
    $matches=array();
    if (preg_match('!/downloads/([0-9a-z\?\=]+)!', $response, $matches)) {
      $video_id = $matches[1];
      p('Video ID for cast=' . $cast .' is ' . $video_id);
      return $video_id;
    }
  }
  return false;
}
/*
 * Get the download URL
 */
function get_download_url($video) {
  global $ch;
  p('Calling ' . $video . ' ... ', 1);
  curl_setopt($ch, CURLOPT_URL, $video);
  $response=curl_exec($ch);
  if ($response===false) {
    p(LD_NL . 'curl_error is ' . curl_error($ch));
    curl_close($ch);
    p(' failed');
    return false;
  }
  p(' success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 302 Found!', $response)) {
    p('Found 302 Found, checking for Location again');
    $matches=array();
    if (preg_match('!Location: (.*)!', $response, $matches)) {
      $download_url = trim($matches[1]);
      if ($download_url === 'https://laracasts.com/admin/subscription/plan') {
        p('Lession requires subscription');
        echo LD_NL;
        p(' exiting script, cannot download with a subscription');
        die;
        return false;
      }
      p('Download URL for video=' . $video .' url is ' . $download_url);
      return trim($download_url);
    }
  }
  return false;
}
/*
 * Download from Vimeo after some redirects
 * Using command line curl because following
 * redirects in php is usually disabled by
 * safe_mode/open_basedr
 */
function download_from_vimeo($out_file, $url) {
  $cmd="/usr/bin/curl -L -o $out_file -A 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.117 Safari/537.36' '$url'";
  p('Executing command ' . $cmd);
  passthru($cmd);
  if (file_exists($out_file)) return true;
  else return false;
}
/*
 * Outputs message based on debug setting
 */
function p ($message, $nonewline = 0) {
  print $message . ($nonewline ? '' : LD_NL);
}
function dump_response_to_file($response) {
  if (!LD_DEBUG) return;
  $out_file=microtime(true) . '-response.html';
  file_put_contents($out_file, $response);
  p('Response dumped to ' . $out_file);
}
