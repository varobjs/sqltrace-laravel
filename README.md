# 介绍

大量依赖数据库的业务，可以通过记录生命周期内所有 SQL 及其 SQL 执行位置，来调试代码。

## 版本要求 

Laravel 版本 >= 6

PHP version >= 7.2


## 安装

```
composer require --dev varobj/laravel-sqltrace dev-master
```

## 使用

1. 编辑项目的文件 `app/Providers/EventServiceProvider.php`

`$listen` 数组中添加下面一行

```
\Illuminate\Database\Events\QueryExecuted::class => [ \LaravelSQLTrace\SQLTraceEventListener::class, ],
```

2. 变更文件不提交到服务器

```
git update-index --skip-worktree composer.json
git update-index --skip-worktree app/Providers/EventServiceProvider.php
```

3. 变更文件和线上冲突时，撤回忽略

```
git update-index --no-skip-worktree composer.json
git update-index --no-skip-worktree app/Providers/EventServiceProvider.php
```


## 日志默认记录到 /tmp/sql.log
