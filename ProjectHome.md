# Purpose #
The script filters the [MySQL Slow Query Log](http://dev.mysql.com/doc/refman/5.1/en/slow-query-log.html) to show queries which impacted performance most and is intended to be used by DB admins and application developers.

The log file is analyzed and processed as a stream, line after line, so there is no need to load the whole log file into memory.

--no-duplicates is a very useful option to see only necessary statistics.

--incremental remembers last input file positions and statistics in a SQLite 3 database, so periodical executions on the same files run much faster.

The Python version is usually 3-5 times faster than the PHP5 version.

You are welcome to contribute a version translated into your favorite scripting language, just open a feature request under Issues i.e. to add a Perl version.


## Usage Examples ##
```
# Filter slow queries executed for at least 3 seconds not from root, remove duplicates,
# apply execution count as first sorting value and save first 10 unique queries to file.
# In addition, remember last input file position and statistics.
php mysql_filter_slow_log.php -T=3 -eu=root --no-duplicates --sort-execution-count --top=10 --incremental linux-slow.log > mysql-slow-queries.log

# Start permanent filtering of all slow queries from now on: at least 3 seconds or examining 10000 rows, exclude users root and test
tail -f -n 0 linux-slow.log | python mysql_filter_slow_log.py -T=3 -R=10000 -eu=root -eu=test &
# (-n 0 outputs only lines generated after start of tail)

# Stop permanent filtering
kill `ps auxww | grep 'tail -f -n 0 linux-slow.log' | egrep -v grep | awk '{print $2}'`
```

## Filter Options ##
```
-T=min_query_time
-R=min_rows_examined

-ih, --include-host
-eh, --exclude-host
-iu, --include-user
-eu, --exclude-user
-iq, --include-query

--date=date_first-date_last Include only queries between date_first (and date_last).
                            Input:                    Date Range:
                            13.11.2006             -> 13.11.2006 - 14.11.2006 (exclusive)
                            13.11.2006-15.11.2006  -> 13.11.2006 - 16.11.2006 (exclusive)
                            15-11-2006-11/13/2006  -> 13.11.2006 - 16.11.2006 (exclusive)
                            >13.11.2006            -> 14.11.2006 - later
                            13.11.2006-            -> 13.11.2006 - later
                            <13.11.2006            -> earlier    - 13.11.2006 (exclusive)
                            -13.11.2006            -> earlier    - 14.11.2006 (exclusive)
                            Please do not forget to escape the greater or lesser than symbols (><, i.e. '--date=>13.11.2006').
                            Short dates are supported if you include a trailing separator (i.e. 13.11.-11/15/).

--incremental Remember input file positions and optionally --no-duplicates statistics between executions in mysql_filter_slow_log.sqlite3

--no-duplicates Powerful option to output only unique query strings with additional statistics:
                Execution count, first and last timestamp.
                Query time: avg / max / sum.
                Lock time: avg / max / sum.
                Rows examined: avg / max / sum.
                Rows sent: avg / max / sum.

--no-output Do not print statistics, just update database with incremental statistics

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

--sort=sum-query-time,avg-query-time,max-query-time,...   You can include multiple sorting values separated by commas.
--sort=sqt,aqt,mqt,slt,alt,mlt,sre,are,mre,ec,srs,ars,mrs Every long sorting option has an equivalent short form (first character of each word).

--top=max_unique_query_count Output maximal max_unique_query_count different unique queries
--details                    Enables output of timestamp based unique query time lines after user list
                             (i.e. # Query_time: 81  Lock_time: 0  Rows_sent: 884  Rows_examined: 2448350).

--help Output this message only and quit

[multiple] options can be passed more than once to set multiple values.
[position] options take the position of their first occurrence into account.
           The first passed option will replace the default first sorting, ...
           Remaining default ordering options will keep their relative positions.
```

## Activate the MySQL Slow Query Log ### I.e. you could add the following lines under the [mysqld] section of your my.ini or my.cnf configuration file:

# Log all queries taking more than 3 seconds
long_query_time=3  # minimum: 1, default: 10

# MySQL >= 5.1.21 (or patched): 3 seconds = 3000000 microseconds
# long_query_time=3.000000  # minimum: 0.000001 (1 microsecond)

# Activate the Slow Query Log
slow_query_log  # >= 5.1.29
# log-slow-queries  # deprecated since 5.1.29

# Write to a custom file name (>= 5.1.29)
# slow_query_log_file=file_name  # default: /data_dir/host_name-slow.log

# Log all queries without indexes
# log-queries-not-using-indexes

# Log only queries which examine at least N rows (>= 5.1.21)
# min_examined_row_limit=1000  # default: 0

# Log slow OPTIMIZE TABLE, ANALYZE TABLE, and ALTER TABLE statements
# log-slow-admin-statements

# Log slow queries executed by replication slaves (>= 5.1.21)
# log-slow-slave-statements

# MySQL 5.1.6 through 5.1.20 had a default value of log-output=TABLE, so you should force
# Attention: logging to TABLE only includes whole seconds information
log-output=FILE


## Admin query for online activation is possible since MySQL 5.1 (without server restart)
## SET @@global.slow_query_log=1
## SET @@global.long_query_time=1


## Show current variables related to the Slow Query Log
## SHOW GLOBAL VARIABLES WHERE Variable_name REGEXP 'admin|min_examined|log_output|log_queries|log_slave|long|slow_quer'```