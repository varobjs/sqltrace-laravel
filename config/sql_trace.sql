drop table if exists trace_sql;
drop table if exists trace_sql_file;
drop table if exists trace_sql_file_code;

create table trace_sql
(
    id              bigint unsigned                           not null primary key auto_increment,
    app_trace_id    varchar(16)  default ''                   not null comment 'sql执行所在生命周期的trace_id',
    sql_trace_id    varchar(16)  default ''                   not null comment '当前sql的trace_id',
    db_host         varchar(32)  default ''                   not null comment '数据库地址',
    exec_host       varchar(32)  default ''                   not null comment 'sql执行所在机器',
    exec_time       int unsigned default 0                    not null comment 'sql执行毫秒时间',
    pid             int unsigned default 0                    not null comment '程序的PID',
    php_sapi        varchar(16)  default ''                   not null comment 'php运行模式',
    request_uri     varchar(256) default ''                   not null comment 'fpm模式=REQUEST_URI;cli模式=$argv',
    referer         varchar(256) default ''                   not null comment '仅在fpm模式下，页面来源',
    trace_sql_md5   varchar(32)  default ''                   not null comment '执行的sql',
    trace_sql       varchar(256) default ''                   not null comment '执行的sql',
    trace_sql_binds varchar(512) default ''                   not null comment '参数绑定值',
    created_at      datetime(3)  default CURRENT_TIMESTAMP(3) not null comment '创建时间'
);

create table trace_sql_file
(
    id           bigint unsigned                           not null primary key auto_increment,
    sql_trace_id varchar(16)  default ''                   not null comment '当前sql的trace_id',
    file         varchar(128) default ''                   not null comment '所在文件',
    line         int unsigned default 0                    not null comment '所在行数',
    class        varchar(128) default ''                   not null comment '类&函数',
    created_at   datetime(3)  default CURRENT_TIMESTAMP(3) not null comment '创建时间'
);

create table trace_sql_file_code
(
    id          bigint unsigned                              not null primary key auto_increment,
    file_id     bigint unsigned default 0                    not null comment '[table]trace_sql_file.id',
    source_code text                                         not null comment '源码',
    created_at  datetime(3)     default CURRENT_TIMESTAMP(3) not null comment '创建时间'
);