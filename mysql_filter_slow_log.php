<?php
/*
MySQL Log Filter 1.0
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
 * Usage:
 *
 * # Filter slow queries executed from other users than root for at least 3 seconds, remove duplicates and save result to file
 * php mysql_filter_slow_log.php -T=3 -eu=root --filter-duplicates < /opt/mysql/data/linux-slow.log > mysql-slow-queries.log
 *
 * # Start permanent filtering of all slow queries from now on: at least 3 seconds or examining 10000 rows, exclude users root and test
 * tail -f -n 0 /opt/mysql/data/linux-slow.log | php mysql_filter_slow_log.php -T=3 -R=10000 -eu=root -eu=test &
 * # (-n 0 outputs only lines generated after start of tail)
 *
 * # Stop permanent filtering
 * kill `ps auxww | grep 'tail -f -n 0 /opt/mysql/data/linux-slow.log' | egrep -v grep | awk '{print $2}'`
 *
 *
 * Options:
 * -T=min_query_time Include only queries which took as long as min_query_time seconds or longer [default: 1]
 * -R=min_rows_examined Include only queries which examined min_rows_examined rows or more
 *
 * -iu=include_user Include only queries which contain include_user in the user field [multiple]
 * -eu=exclude_user Exclude all queries which contain exclude_user in the user field [multiple]
 * -iq=include_query Include only queries which contain the string include_query (i.e. database or table name) [multiple]
 *
 * --filter-duplicates Output only unique query strings with additional statistics: max_query_time, max_rows_examined, sum_query_time, sum_rows_examined, execution count [default sorting: sum_query_time, sum_rows_examined]
 *
 * [multiple] options can be passed more than once to set multiple values.
 *
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


$min_query_time = 1;
$min_rows_examined = 0;
$include_users = array();
$exclude_users = array();
$include_queries = array();
$filter_duplicates = FALSE;
$line_separator = defined('PHP_EOL') ? PHP_EOL : "\n";


foreach($_SERVER['argv'] as $arg) {
  switch(substr($arg, 0, 3)) {
    case '-T=': $min_query_time = (int) substr($arg, 3); break;
    case '-R=': $min_rows_examined = (int) substr($arg, 3); break;
    case '-iu': $include_users[] = substr($arg, 4); break;
    case '-eu': $exclude_users[] = substr($arg, 4); break;
    case '-iq': $include_queries[] = substr($arg, 4); break;
    default:
      switch($arg) {
        case '--filter-duplicates': $filter_duplicates = TRUE; break;
      }
      break;
  }
}
$include_users = array_unique($include_users);
$exclude_users = array_unique($exclude_users);


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
      if($in_query) {
        if($filter_duplicates) {
          $queries[$query][$user][$timestamp] = $query_time;
        } else {
          echo '# Time: ', $timestamp, "$line_separator# User@Host: ", $user, "$line_separator# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]$line_separator", $query, "$line_separator";
        }
      }
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

if($query) {
  if($filter_duplicates) {
    $queries[$query][$user][$timestamp] = $query_time;
  } else {
    echo '# Time: ', $timestamp, "$line_separator# User@Host: ", $user, "$line_separator# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]$line_separator", $query, "$line_separator";
  }
}


if($filter_duplicates) {
  $lines = array();
  foreach($queries as $query => &$users) {
    $execution_count = 0; $sum_query_time = 0; $sum_rows_examined = '0'; $max_query_time = 0; $max_rows_examined = 0;
    $output = '';
    foreach($users as $user => &$timestamps) {
      $output .= "# User@Host: ". $user. "$line_separator"; // 'Executed: ' . sizeof($timestamps). " time" . (sizeof($timestamps) > 1 ? 's' : '') .
      uasort($timestamps, 'cmp_query_times');
      $query_times = array();
      foreach($timestamps as $query_time) {
        $query_times["# Query_time: $query_time[0]  Lock_time: $query_time[1]  Rows_sent: $query_time[2]  Rows_examined: $query_time[3]$line_separator"] = 1;
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
    $output .= "$line_separator" . $query . "$line_separator$line_separator";
    $lines[$query] = array($output, $sum_query_time, $sum_rows_examined, $max_query_time, $max_rows_examined, $execution_count);
  }

  uasort($lines, 'cmp_queries');
  foreach($lines as $query => &$data) {
    list($output, $sum_query_time, $sum_rows_examined, $max_query_time, $max_rows_examined, $execution_count) = $data;
    echo "# Execution count: $execution_count. Query time: avg=", number_format(round($sum_query_time / $execution_count, 2), 2, '.', ','), " / max=$max_query_time / sum=$sum_query_time. Rows examined: avg=", number_format(bcdiv($sum_rows_examined, $execution_count, 0), 0, '.', ','), " / max=", number_format($max_rows_examined, 0, '.', ','), " / sum=", number_format($sum_rows_examined, 0, '.', ','), ". $line_separator", $output;
  }
}



function cmp_queries(&$a, &$b) {
  if($a[1] != $b[1]) return $a[1] < $b[1] ? 1 : -1;
  return -1 * bccomp($a[2], $b[2]);
}

function cmp_query_times(&$a, &$b) {
  if($a[0] != $b[0]) return $a[0] < $b[0] ? 1 : -1;
  if($a[1] != $b[1]) return $a[1] < $b[1] ? 1 : -1;
  if($a[3] != $b[3]) return $a[3] < $b[3] ? 1 : -1;
  return 0;
}

?>