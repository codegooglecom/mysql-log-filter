<?php
/*
MySQL Log Filter 1.4
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

$usage = "MySQL Slow Query Log Filter 1.4 for PHP5 (requires BCMath extension)

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
                Execution count, first and last timestamp.
                Query time: avg / max / sum.
                Lock time: avg / max / sum.
                Rows examined: avg / max / sum.
                Rows sent: avg / max / sum.

Default ordering of unique queries:
--sort-sum-query-time    [ 1. position]
--sort-avg-query-time    [ 2. position]
--sort-max-query-time    [ 3. position]
--sort-sum-lock-time     [ 4. position]
--sort-avg-lock-time     [ 5. position]
--sort-max-lock-time     [ 6. position]
--sort-sum-rows-examined [ 7. position]
--sort-avg-rows-examined [ 8. position]
--sort-max-rows-examined [ 9. position]
--sort-execution-count   [10. position]
--sort-sum-rows-sent     [11. position]
--sort-avg-rows-sent     [12. position]
--sort-max-rows-sent     [13. position]

--top=max_unique_qery_count Output maximal max_unique_qery_count different unique qeries
--details                   Enables output of timestamp based unique query time lines after user list
                            (i.e. # Query_time: 81  Lock_time: 0  Rows_sent: 884  Rows_examined: 2448350).

--help Output this message only and quit

[multiple] options can be passed more than once to set multiple values.
[position] options take the position of their first occurence into account.
           The first passed option will replace the default first sorting, ...
           Remaining default ordering options will keep their relative positions.";

function cmp_queries(&$a, &$b) {
  foreach($GLOBALS['new_sorting'] as $i)
    if($a[$i] != $b[$i])
      return 10 == $i || 13 == $i ? -1 * bccomp($a[$i], $b[$i]) : ($a[$i] < $b[$i] ? 1 : -1);
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
$details = FALSE;
$ls = defined('PHP_EOL') ? PHP_EOL : "\n";
$default_sorting = array_flip(array(4=>'sum-query-time', 2=>'avg-query-time', 3=>'max-query-time', 7=>'sum-lock-time', 5=>'avg-lock-time', 6=>'max-lock-time', 13=>'sum-rows-examined', 11=>'avg-rows-examined', 12=>'max-rows-examined', 1=>'execution-count', 10=>'sum-rows-sent', 8=>'avg-rows-sent', 9=>'max-rows-sent'));
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
        case '--details': $details = TRUE; break;
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


while(! feof(STDIN)) {
  if(! ($line = stream_get_line(STDIN, 10000, "\n"))) continue;
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
    $execution_count = $max_timestamp = 0;
    $min_timestamp = 2147483647;
    $sum_query_time = $max_query_time = 0;
    $sum_lock_time = $max_lock_time = 0;
    $sum_rows_examined = '0'; $max_rows_examined = 0;
    $sum_rows_sent = '0'; $max_rows_sent = 0;
    $output = '';
    ksort($users);
    foreach($users as $user => &$timestamps) {
      $output .= "# User@Host: ". $user. $ls;
      uasort($timestamps, 'cmp_query_times');
      $query_times = array();
      foreach($timestamps as $t => $query_time) {
        // Note: strptime not available on Windows
        $t = mktime (substr($t,7,2), substr($t,10,2), substr($t,13,2), substr($t,2,2), substr($t,4,2), substr($t,0,2) + 2000);
        $query_times["# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]$ls"] = 1;
        if($query_time[0] > $max_query_time)
          $max_query_time = $query_time[0];
        if($query_time[1] > $max_lock_time)
          $max_lock_time = $query_time[1];
        if($query_time[2] > $max_rows_sent)
          $max_rows_sent = $query_time[2];
        if($query_time[3] > $max_rows_examined)
          $max_rows_examined = $query_time[3];
        if($t < $min_timestamp)
          $min_timestamp = $t;
        else if($t > $max_timestamp)
          $max_timestamp = $t;
        $sum_query_time += $query_time[0];
        $sum_lock_time += $query_time[1];
        $sum_rows_sent = bcadd($sum_rows_sent, $query_time[2]);
        $sum_rows_examined = bcadd($sum_rows_examined, $query_time[3]);
        $execution_count++;
      }
      if($details)
        $output .= implode('', array_keys($query_times));
    }
    $output .= $ls . $query . $ls . $ls;
    $avg_query_time = round($sum_query_time / $execution_count, 1);
    $avg_lock_time = round($sum_lock_time / $execution_count, 1);
    $avg_rows_sent = bcdiv($sum_rows_sent, $execution_count, 1);
    $avg_rows_examined = bcdiv($sum_rows_examined, $execution_count, 1);
    $lines[$query] = array($output, $execution_count, $avg_query_time, $max_query_time, $sum_query_time, $avg_lock_time, $max_lock_time, $sum_lock_time, $avg_rows_sent, $max_rows_sent, $sum_rows_sent, $avg_rows_examined, $max_rows_examined, $sum_rows_examined, $min_timestamp, $max_timestamp);
  }

  uasort($lines, 'cmp_queries');
  $i = 0;
  foreach($lines as $query => &$data) {
    // Determine maximum size for each column
    $max_length = array(3,3,3);
    for($k=2; $k < 14; $k++) {
      $c = $k % 3;
      $c = $c == 2 ? 0 : $c + 1; // 2 -> 2 -> 0 | 3 -> 0 -> 1 | 4 -> 1 -> 2
      $data[$k] = number_format($data[$k], $c == 0 ? 1 : 0, '.', ',');
      if(($l = strlen($data[$k])) > $max_length[$c])
        $max_length[$c] = $l;
    }

    // Remove trailing 0 if all average values end with it
    for($k=1; $k<3; $k++) {
      foreach(array(2,5,8,11) as $c)
        if(substr($data[$c], -1) != 0)
          break 2;
      foreach(array(2,5,8,11) as $c)
        $data[$c] = substr($data[$c], 0, -1);
      $max_length[0]--;
    }

    list($output, $execution_count, $avg_query_time, $max_query_time, $sum_query_time, $avg_lock_time, $max_lock_time, $sum_lock_time, $avg_rows_sent, $max_rows_sent, $sum_rows_sent, $avg_rows_examined, $max_rows_examined, $sum_rows_examined, $min_timestamp, $max_timestamp) = $data;

    $execution_count = number_format($data[1], 0, '.', ',');
    echo "# Execution count: $execution_count time", ($data[1] == 1 ? '' : 's') . ' ';
    if($max_timestamp)
      echo "between ", date('Y-m-d H:i:s', $min_timestamp), ' and ', date('Y-m-d H:i:s', $max_timestamp);
    else
      echo "on ", date('Y-m-d H:i:s', $min_timestamp);
    echo '.', $ls;

    echo "# Column       : ", str_pad('avg', $max_length[0], ' ', STR_PAD_LEFT), " | ", str_pad('max', $max_length[1], ' ', STR_PAD_LEFT), " | ", str_pad('sum', $max_length[2], ' ', STR_PAD_LEFT), $ls;
    echo "# Query time   : ", str_pad($avg_query_time, $max_length[0], ' ', STR_PAD_LEFT), " | ", str_pad($max_query_time, $max_length[1], ' ', STR_PAD_LEFT), " | ", str_pad($sum_query_time, $max_length[2], ' ', STR_PAD_LEFT), $ls;
    echo "# Lock time    : ", str_pad($avg_lock_time, $max_length[0], ' ', STR_PAD_LEFT), " | ", str_pad($max_lock_time, $max_length[1], ' ', STR_PAD_LEFT), " | ", str_pad($sum_lock_time, $max_length[2], ' ', STR_PAD_LEFT), $ls;
    echo "# Rows examined: ", str_pad($avg_rows_examined, $max_length[0], ' ', STR_PAD_LEFT), " | ", str_pad($max_rows_examined, $max_length[1], ' ', STR_PAD_LEFT), " | ", str_pad($sum_rows_examined, $max_length[2], ' ', STR_PAD_LEFT), $ls;
    echo "# Rows sent    : ", str_pad($avg_rows_sent, $max_length[0], ' ', STR_PAD_LEFT), " | ", str_pad($max_rows_sent, $max_length[1], ' ', STR_PAD_LEFT), " | ", str_pad($sum_rows_sent, $max_length[2], ' ', STR_PAD_LEFT), $ls;
    echo $output;

    if($top) {
      $i++;
      if($i >= $top)
        break;
    }
  }
}

?>