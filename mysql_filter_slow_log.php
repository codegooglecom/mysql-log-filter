<?php
/*
MySQL Log Filter 1.3
=====================

Copyright 2007 René Leonhardt

Website: http://code.google.com/p/mysql-log-filter/

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

ABOUT MySQL Log Filter
=======================
The MySQL Log Filter allows you to easily filter only useful information of the
MySQL Slow Query Log.


LICENSE
=======
This code is released under the GPL. The full code is available from the website
listed above.

*/


/**
 * Parse the MySQL Slow Query Log from STDIN and write all filtered queries to STDOUT.
 *
 * In order to activate it see http://dev.mysql.com/doc/refman/5.1/en/slow-query-log.html.
 * For example you could add the following lines to your my.ini or my.cnf configuration file under the [mysqld] section:
 *
 * long_query_time=3
 * log-slow-queries
 * log-queries-not-using-indexes
 *
 *
 * Required PHP extensions:
 * - BCMath
 *
 *
 * Example input lines:
 *
 * # Time: 070119 12:29:58
 * # User@Host: root[root] @ localhost []
 * # Query_time: 1  Lock_time: 0  Rows_sent: 1  Rows_examined: 12345
 * SELECT * FROM test;
 *
 */

$usage = "MySQL Slow Query Log Filter 1.3 for PHP5 (requires BCMath extension)

Usage:
# Filter slow queries executed for at least 3 seconds not from root, remove duplicates,
# apply execution count as first sorting value and save first 10 unique queries to file
php mysql_filter_slow_log.php -T=3 -eu=root --no-duplicates --sort-execution-count --top=10 < linux-slow.log > mysql-slow-queries.log

# Start permanent filtering of all slow queries from now on: at least 3 seconds or examining 10000 rows, exclude users root and test
tail -f -n 0 linux-slow.log | php mysql_filter_slow_log.php -T=3 -R=10000 -eu=root -eu=test &
# (-n 0 outputs only lines generated after start of tail)

# Stop permanent filtering
kill `ps auxww | grep 'tail -f -n 0 linux-slow.log' | egrep -v grep | awk '{print $2}'`


Options:
-T=min_query_time    Include only queries which took at least min_query_time seconds [default: 1]
-R=min_rows_examined Include only queries which examined at least min_rows_examined rows

-iu=include_user  Include only queries which contain include_user in the user field [multiple]
-eu=exclude_user  Exclude all queries which contain exclude_user in the user field [multiple]
-iq=include_query Include only queries which contain the string include_query (i.e. database or table name) [multiple]

--no-duplicates Output only unique query strings with additional statistics:
                Execution count, Query time: avg / max / sum, Rows examined: avg / max / sum

Default ordering of unique queries:
--sort-sum-query-time    [1. position]
--sort-avg-query-time    [2. position]
--sort-max-query-time    [3. position]
--sort-sum-rows-examined [4. position]
--sort-avg-rows-examined [5. position]
--sort-max-rows-examined [6. position]
--sort-execution-count   [7. position]

--top=max_unique_qery_count Output maximal max_unique_qery_count different unique qeries

--help Output this message only and quit

[multiple] options can be passed more than once to set multiple values.
[position] options take the position of their first occurence into account.
           The first passed option will replace the default first sorting, ...
           Remaining default ordering options will keep their relative positions.";

function cmp_queries(&$a, &$b) {
  foreach($GLOBALS['new_sorting'] as $i)
    if($a[$i] != $b[$i])
      return 7 == $i ? -1 * bccomp($a[$i], $b[$i]) : ($a[$i] < $b[$i] ? 1 : -1);
  return 0;
}

function cmp_query_times(&$a, &$b) {
  foreach(array(0,1,3) as $i)
    if($a[$i] != $b[$i]) return $a[$i] < $b[$i] ? 1 : -1;
  return 0;
}

function process_query(&$queries, $query, $no_duplicates, $user, $timestamp, $query_time, $ls) {
  if($no_duplicates)
    $queries[$query][$user][$timestamp] = $query_time;
  else
    echo '# Time: ', $timestamp, $ls, "# User@Host: ", $user, $l, "# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]", $ls, $query, $ls;
}


$min_query_time = 1;
$min_rows_examined = 0;
$include_users = array();
$exclude_users = array();
$include_queries = array();
$no_duplicates = FALSE;
$ls = defined('PHP_EOL') ? PHP_EOL : "\n";
$default_sorting = array_flip(array(4=>'sum-query-time', 2=>'avg-query-time', 3=>'max-query-time', 7=>'sum-rows-examined', 5=>'avg-rows-examined', 6=>'max-rows-examined', 1=>'execution-count'));
$new_sorting = array();
$top = 0;

foreach($_SERVER['argv'] as $arg) {
  switch(substr($arg, 0, 3)) {
    case '-T=': $min_query_time = abs(substr($arg, 3)); break;
    case '-R=': $min_rows_examined = abs(substr($arg, 3)); break;
    case '-iu': $include_users[] = substr($arg, 4); break;
    case '-eu': $exclude_users[] = substr($arg, 4); break;
    case '-iq': $include_queries[] = substr($arg, 4); break;
    default:
      if(substr($arg, 0, 7) == '--sort-') {
        $sorting = substr($arg, 7);
        if(isset($default_sorting[$sorting]) && ! in_array($default_sorting[$sorting], $new_sorting))
          $new_sorting[] = $default_sorting[$sorting];
      } else if(substr($arg, 0, 6) == '--top=') {
        $_top = abs(substr($arg, 6));
        if($_top)
          $top = $_top;
      } else switch($arg) {
        case '--no-duplicates': $no_duplicates = TRUE; break;
        case '--help': fwrite(STDERR, $usage); exit(0);
      }
      break;
  }
}
$include_users = array_unique($include_users);
$exclude_users = array_unique($exclude_users);
foreach($default_sorting as $i)
  if(! in_array($i, $new_sorting))
    $new_sorting[] = $i;


$in_query = FALSE;
$query = '';
$timestamp = '';
$user = '';
$query_time = array();
$queries = array();


while($line = stream_get_line(STDIN, 10000, "\n")) {
  if($line[0] == '#' && $line[1] == ' ') {
    if($query) {
      if($include_queries) {
        $in_query = FALSE;
        foreach($include_queries as $iq)
          if(FALSE !== stripos($query, $iq)) {
            $in_query = TRUE;
            break;
          }
      }
      if($in_query)
        process_query($queries, $query, $no_duplicates, $user, $timestamp, $query_time, $ls);
      $query = '';
      $in_query = FALSE;
    }

    if($line[2] == 'T')  // # Time: 070119 12:29:58
      $timestamp = substr($line, 8);
    else if($line[2] == 'U') { // # User@Host: root[root] @ localhost []
      $user = substr($line, 13);

      if(! $include_users) {
        $in_query = TRUE;
        foreach($exclude_users as $eu)
          if(FALSE !== stripos($user, $eu)) {
            $in_query = FALSE;
            break;
          }
      } else {
        $in_query = FALSE;
        foreach($include_users as $iu)
          if(FALSE !== stripos($user, $iu)) {
            $in_query = TRUE;
            break;
          }
      }
    }
    else if($in_query && $line[2] == 'Q') { // # Query_time: 0  Lock_time: 0  Rows_sent: 0  Rows_examined: 156
      $numbers = explode(': ', substr($line, 12));
      $query_time = array((int) $numbers[1], (int) $numbers[2], (int) $numbers[3], (int) $numbers[4]);
      $in_query = $query_time[0] >= $min_query_time || ($min_rows_examined && $query_time[3] >= $min_rows_examined);
    }
  } else if($in_query) {
    $query .= $line;
  }

}

if($query)
  process_query($queries, $query, $no_duplicates, $user, $timestamp, $query_time, $ls);


if($no_duplicates) {
  $lines = array();
  foreach($queries as $query => &$users) {
    $execution_count = 0; $sum_query_time = 0; $sum_rows_examined = '0'; $max_query_time = 0; $max_rows_examined = 0;
    $output = '';
    ksort($users);
    foreach($users as $user => &$timestamps) {
      $output .= "# User@Host: ". $user. $ls;
      uasort($timestamps, 'cmp_query_times');
      $query_times = array();
      foreach($timestamps as $query_time) {
        $query_times["# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]$ls"] = 1;
        if($query_time[0] > $max_query_time)
          $max_query_time = $query_time[0];
        if($query_time[3] > $max_rows_examined)
          $max_rows_examined = $query_time[3];
        $sum_query_time += $query_time[0];
        $sum_rows_examined = bcadd($sum_rows_examined, $query_time[3]);
        $execution_count++;
      }
      $output .= implode('', array_keys($query_times));
    }
    $output .= $ls . $query . $ls . $ls;
    $avg_query_time = round($sum_query_time / $execution_count, 2);
    $avg_rows_examined = bcdiv($sum_rows_examined, $execution_count, 0);
    $lines[$query] = array($output, $execution_count, $avg_query_time, $max_query_time, $sum_query_time, $avg_rows_examined, $max_rows_examined, $sum_rows_examined);
  }

  uasort($lines, 'cmp_queries');
  $i = 0;
  foreach($lines as $query => &$data) {
    list($output, $execution_count, $avg_query_time, $max_query_time, $sum_query_time, $avg_rows_examined, $max_rows_examined, $sum_rows_examined) = $data;
    echo "# Execution count: $execution_count. Query time: avg=", number_format($avg_query_time, 2, '.', ','), " / max=$max_query_time / sum=$sum_query_time. Rows examined: avg=", number_format($avg_rows_examined, 0, '.', ','), " / max=", number_format($max_rows_examined, 0, '.', ','), " / sum=", number_format($sum_rows_examined, 0, '.', ','), '.', $ls, $output;
    $i++;
    if($i >= $top)
      break;
  }
}

?>