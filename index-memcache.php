<?php
// Copyright (C) 2013 Jayson Cena, ClusterFoundry (jayson.cena at clusterfoundry.com)
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software Foundation,
// Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
//
//
// By: Jayson Cena
// Based from http://eamann.com/tech/ludicrous-speed-wordpress-caching-with-redis/ but using memcached instead of redis
// Serves cache in 0.5ms/0.0005 seconds from a 64GBRAM/Intel Xeon 6-Core,12 threads/2x2TB SATA3
// Running on NginX and PHP5-FPM
//
// Can also be used on other PHP apps aside from Wordpress with few modifications
//

$debug = 1;
$start = microtime();

$memcached_ip = "127.0.0.1";
$memcached_port = "11211";
$cached_ttl = 86400; // 1-day TTL

// from wordpress
define('WP_USE_THEMES', true);

// init memcached
$domain = $_SERVER['HTTP_HOST'];
$url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$url = str_replace('?r=y', '', $url);
$url = str_replace('?c=y', '', $url);
$url_key = md5($url);

// check if page isn't a comment submission
if (isset($_SERVER['HTTP_CACHE_CONTROL'])) {
	if ($_SERVER['HTTP_CACHE_CONTROL'] == 'max-age=0') {
		$submit = 1;
	} else {
		$submit = 0;
	}
} else {
	$submit = 0;
}

// check if logged in to wp
$cookie = var_export($_COOKIE, true);
//$loggedin = preg_match("/wordpress_logged_in/", $cookie);
$loggedin = 0;

$memcache = new Memcache;
$memcache->connect($memcached_ip, $memcached_port);

$html_body = $memcache->get($url_key);
// check if the cache of the page exists
if ($html_body != NULL && $loggedin == 0 && $submit == 0) {

	echo $html_body;
	if (!$debug) exit(0);
	$msg = 'served from cached loggedin=' . $loggedin . ' submit=' . $submit . ' url=' . $url;

// if a comment was submitted or clear page cache request was made delete cache of page
} else if ($submit == 1 || substr($_SERVER['REQUEST_URI'], -4) == '?r=y') {

    require('./wp-blog-header.php');
    $result = $memcache->delete($url_key);
    $msg = 'cache of page deleted loggedin=' . $loggedin . ' submit=' . $submit . ' url=' . $url;

// delete entire cache, works only if logged in
//} else if ($loggedin && substr($_SERVER['REQUEST_URI'], -4) == '?c=y') {
} else if (substr($_SERVER['REQUEST_URI'], -4) == '?c=y') {

	require('./wp-blog-header.php');
	$result = $memcache->flush();
	$msg = 'domain cache flushed loggedin=' . $loggedin . ' submit=' . $submit . ' url=' . $url;
	
} else if ($loggedin == 1) {

    require('./wp-blog-header.php');
    $msg = 'not cached loggedin=' . $loggedin . ' submit=' . $submit . ' url=' . $url;

} else {
	// save body to memcached
	// enable output buffering
	ob_start();
    require('./wp-blog-header.php');

    // get contents of output buffer
    $html_body = ob_get_contents();

    // clean output buffer
    ob_end_clean();
    echo $html_body;

    // store html contents to redis cache
    $result = $memcache->set($url_key, $html_body,  MEMCACHE_COMPRESSED, $cached_ttl);
    $msg = 'cache is set  loggedin=' . $loggedin . ' submit=' . $submit . ' url=' . $url;
}
$end = microtime(); // get end execution time

// show messages if debug is enabled
if ($debug) {
    echo '<!-- '.$msg.': ';
    echo t_exec($start, $end);
//    echo '\n' . $cookie;
//    echo "\nDOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'];
//    echo "\nHTTP_HOST: " . $_SERVER['HTTP_HOST'];
//    echo "\nREQUEST_URI: " . $_SERVER['REQUEST_URI'];
//    echo "\nQUERY_STRING: " . $_SERVER['QUERY_STRING'];
    echo ' -->';
}
// time diff
function t_exec($start, $end) {
    $t = (getmicrotime($end) - getmicrotime($start));
    return round($t,5);
}

// get time
function getmicrotime($t) {
    list($usec, $sec) = explode(" ",$t);
    return ((float)$usec + (float)$sec);
}
?>
