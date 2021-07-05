create table trace_sql
(
    timestamp       DateTime64(4) comment '时间',
    app_trace_id    String       default '' comment 'sql执行所在生命周期的trace_id',
    sql_trace_id    String       default '' comment '当前sql的trace_id',
    db_host         String       default '' comment '数据库地址',
    exec_host       String       default '' comment 'sql执行所在机器',
    exec_time       Decimal64(3) default 0.00 comment 'sql执行毫秒时间',
    pid             UInt16       default 0 comment '程序的PID',
    php_sapi        String       default '' comment 'php运行模式',
    request_uri     String       default '' comment 'fpm模式=REQUEST_URI;cli模式=$argv',
    referer         String       default '' comment '仅在fpm模式下，页面来源',
    trace_sql_md5   String       default '' comment '执行的sql',
    trace_sql       String       default '' comment '执行的sql',
    trace_sql_binds String       default '' comment '参数绑定值'
) engine MergeTree
      order by timestamp;

create table trace_sql_file
(
    sql_trace_id String     default '' comment '当前sql的trace_id',
    file         String     default '' comment '所在文件',
    line         UInt16     default 0 comment '所在行数',
    class        String     default '' comment '类&函数',
    created_at   DateTime64 default now() comment '创建时间'
) engine MergeTree
      order by created_at;
