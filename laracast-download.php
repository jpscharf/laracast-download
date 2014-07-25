<?php

set_time_limit(0); // infinity
define('LD_DEBUG', false);
define('LD_CONFIG_FILE', dirname(__FILE__) . '/config.php');
define('LD_COOKIEJAR', dirname(__FILE__).'/cookie.jar');

if (!file_exists(LD_CONFIG_FILE)) die('No config file found.'.PHP_EOL);

// Kill the cookie jar
#if (file_exists(LD_CONFIG_FILE)) unlink(LD_COOKIEJAR);

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
// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

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

if (!count(array_diff($casts, $existing))) {
  p('Nothing new to download, dying');
  die;
}

$padding = 4;
$lesson_ids = array();
foreach ($casts as $cast) {
  $lesson_id = get_lesson_id($cast);
  if ($lesson_id === false) {
    p('Failed to get lesson id for cast=' . $cast);
    continue;
  }
  $out_file = $directory . '/' . str_pad($lesson_id, $padding, '0', STR_PAD_LEFT) . '_' . $cast . '.mp4';
  if (file_exists($out_file)) {
    p('File exists for out_file=' . $out_file . ', skipping');
    continue;
  }
  $url = get_download_url($lesson_id);
  if ($url === false) {
    p('Could not get download url for lession_id=' . $lession_id);
  }
  p('Downloading ' . $url . ' to ' . $out_file);
  $result = download_from_vimeo($out_file, $url);
  if ($result===false) {
    p('Failed to get url=' . $url);
  }
  p('Success, got lesson_id=' . $lesson_id . ', cast=' . $cast);
}

#functions
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
  #curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/user/login');
  $response=curl_exec($ch);
  if ($response===false) {
    curl_close($ch);
    p('failed');
    return false;
  }
  p('success');

  // Post login
  p('Posting to /session', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/sessions');
  #curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/user/login');
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('email' => $email, 'password' => $password, 'remember' => 'on')));
  $response=curl_exec($ch);
  if ($response===false) {
    curl_close($ch);
    p('failed');
    return false;
  }
  if (preg_match('!^HTTP/1.1 302 Found!', $response)) {
    p('success, got 302 from /sessions, looking for Location');
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
  p('Calling /admin/profile ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/admin/profile');
  #curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/user/login');
  $response=curl_exec($ch);
  if ($response===false) {
    curl_close($ch);
    p('failed');
    return false;
  }
  p('success');
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
    curl_close($ch);
    p('failed');
    return false;
  }
  p('success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 200 OK!', $response)) {
    p('Found 200 OK, checking cast links');
    $matches=array();
    $yup = preg_match_all('!https://laracasts.com/lessons/([^"]+)!', $response, $matches);
    if ($yup) {
      $casts=array();
      foreach($matches[1] as $i => $match) {
        if ($match<>'complete') $casts[]=$match;
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
 * Get download url from /lessons/<cast>
 */
function get_lesson_id($cast) {
  global $ch;
  p('Calling /lessons/' . $cast . ' ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/lessons/' . $cast);
  $response=curl_exec($ch);
  if ($response===false) {
    curl_close($ch);
    p('failed');
    return false;
  }
  p('success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 200 OK!', $response)) {
    p('Found 200 OK, checking cast links');
    $matches=array();
    if (preg_match('!/downloads/([0-9a-z\?\=]+)!', $response, $matches)) {
      $lesson_id = $matches[1];
      p('Lesson ID for cast=' . $cast .' is ' . $lesson_id);
      return $lesson_id;
    }
  }
  return false;
}
/*
 * Get the download URL
 */
function get_download_url($lesson_id) {
  global $ch;
  p('Calling /downloads/' . $lesson_id . ' ... ', 1);
  curl_setopt($ch, CURLOPT_URL, 'https://laracasts.com/downloads/' . $lesson_id);
  $response=curl_exec($ch);
  if ($response===false) {
    curl_close($ch);
    p('failed');
    return false;
  }
  p('success');
  dump_response_to_file($response);
  if (preg_match('!^HTTP/1.1 302 Found!', $response)) {
    p('Found 302 Found, checking for Location');
    $matches=array();
    if (preg_match('!Location: (.*)!', $response, $matches)) {
      $download_url = $matches[1];
      p('Lesson ID for id=' . $lesson_id .' url is ' . $download_url);
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
  print $message . ($nonewline ? '' : '<br><br>'.PHP_EOL);
}
function dump_response_to_file($response) {
  if (!LD_DEBUG) return;
  $out_file=microtime(true) . '-response.html';
  file_put_contents($out_file, $response);
  p('Response dumped to ' . $out_file);
}
