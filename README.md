# 介绍

大量依赖数据库的业务，可以通过记录生命周期内所有 SQL 及其 SQL 执行位置，来调试代码。

## 版本要求 

版本 >= 6

PHP version >= 7.2


## 安装

```
composer require varobj/laravel-sqltrace
```

## 使用

编辑项目的文件 `app/Providers/EventServiceProvider.php`

`$listen` 数组中添加下面一行

```
QueryExecuted::class => [ \LaravelSQLTrace\SQLTraceEventListener::class, ]
```


## 效果

```
~ tail -f /tmp/sql*pretty.log
==> /tmp/sql_pretty.log <==

[4CD93FB0 FADCF832][2021-07-05 10:11:24.8761][127.0.0.1][0.730000ms]
**************************************************
select * from `z_doc_info` where (`hosp_member_id` = ?) order by `id` desc limit 1 [74]
**************************************************

[4CD93FB0 54ED27D6][2021-07-05 10:11:24.8782][127.0.0.1][0.580000ms]
**************************************************
select * from `z_hosp_member` where `z_hosp_member`.`id` = ? limit 1 [74]
**************************************************

==> /tmp/sql_trace_pretty.log <==
             └── Illuminate\Database\Eloquent\Builder->first(..) at /code/doc-api/app/DocrApp/Services/DocService.php@167
[FAB5AA96]  App\DocrApp\Services\LoginService->loginRegister(..) at /code/doc-api/app/Http/Controllers/LoginController.php@145
            └── App\DocrApp\Services\DocService->getTmpMemberByMemberId(..) at /code/doc-api/app/DocrApp/Services/LoginService.php@334
             └── Illuminate\Database\Eloquent\Builder->first(..) at /code/doc-api/app/DocrApp/Services/DocService.php@745
[FADCF832]  App\DocrApp\Services\LoginService->loginRegister(..) at /code/doc-api/app/Http/Controllers/LoginController.php@145
            └── App\DocrApp\Services\DocService->getDocInfo(..) at /code/doc-api/app/DocrApp/Services/LoginService.php@335
             └── Illuminate\Database\Eloquent\Builder->first(..) at /code/doc-api/app/DocrApp/Services/DocService.php@757
[54ED27D6]  App\DocrApp\Services\LoginService->fakeLogin(..) at /code/doc-api/app/DocrApp/Services/LoginService.php@358
            └── App\DocrApp\Services\LoginService->registerInitInfo(..) at /code/doc-api/app/DocrApp/Services/LoginService.php@314
             └── Illuminate\Database\Eloquent\Model::__callStatic(..) at /code/doc-api/app/DocrApp/Services/LoginService.php@102
```


## SQL 日志

```
tail -f /tmp/sql_pretty.log

# [app_trace_id sql_trace_id][time][db_host][exec_micro_time] .. Sql ..
```

## SQL Trace 日志

```
tail -f /tmp/sql_trace_pretty.log
# [sql_trace_id] .. log back ..
```


## 快速查询某条 SQL 的 trace

```
grep sql_trace_id -A 10 /tmp/sql_trace_pretty.log
```


# 进阶

## 配置项

```
# 记录 SQL 文件，默认 /tmp/sql.log
SQL_TRACE_SQL_FILE=

# 开启分析模式，true 开启，默认 false 关闭，开启后 & redis 配置有效，统计全部信息
SQL_TRACE_ANALYSE=

# redis 使用的配置
SQL_TRACE_REDIS_HOST=
SQL_TRACE_REDIS_PORT=
SQL_TRACE_REDIS_PASSWORD=
```


## 收集到 Clickhouse

SQL 文件 `./config/sql_trace*.sql`

vector 类似 logstash 等工具 https://vector.dev/docs/setup/quickstart/
配置文件 `./config/vector_sql*.toml`

```
$ sudo vector -c ~/Code/laravel-sqltrace/config/vector*.toml

Jul 05 12:49:29.525  INFO vector::app: Log level is enabled. level="vector=info,codec=info,vrl=info,file_source=info,tower_limit=trace,rdkafka=info"
Jul 05 12:49:29.526  INFO vector::app: Loading configs. path=[("/Users/deli/Code/laravel-sqltrace/config/vector_sql_clickhouse.toml", None), ("/Users/deli/Code/laravel-sqltrace/config/vector_sql_trace_clickhouse.toml", None)]
Jul 05 12:49:30.221  INFO vector::topology: Running healthchecks.
Jul 05 12:49:30.224  INFO vector::topology: Starting source. name="sql_log"
Jul 05 12:49:30.224  INFO vector::topology: Starting source. name="sql_file_log"
Jul 05 12:49:30.224  INFO vector::topology: Starting transform. name="sql_file_log_grok"
Jul 05 12:49:30.224  INFO vector::topology: Starting transform. name="sql_log_grok"
Jul 05 12:49:30.224  INFO vector::topology: Starting transform. name="sql_log_clickhouse"
...
```


## TODO


[x] 完善判断逻辑: 记录日志 or 计数

[ ] 推送指标到第三方接口
