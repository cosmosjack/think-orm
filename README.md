# ThinkORM

基于PHP7.1+ 和PDO实现的ORM，支持多数据库，2.0版本主要特性包括：

* 基于PDO和PHP强类型实现
* 支持原生查询和查询构造器
* 自动参数绑定和预查询
* 简洁易用的查询功能
* 强大灵活的模型用法
* 支持预载入关联查询和延迟关联查询
* 支持多数据库及动态切换
* 支持`MongoDb`
* 支持分布式及事务
* 支持断点重连
* 支持`JSON`查询
* 支持数据库日志
* 支持`PSR-16`缓存及`PSR-3`日志规范


## 安装
~~~
composer require oss_think/think-orm
~~~
##升级
~~~
composer update oss_think/think-orm
~~~

## 文档

详细参考 [ThinkORM开发指南](https://www.kancloud.cn/manual/think-orm/content)

## 案例
~~~
public function test_sql(){
        $where["((key_1&key_2)|(key_9))|(key_3&key_4)|((key_5&key_6)&(key_7&key_8))&(key_10)"] = array(
            array(
                array(
                    array("like","%jack%",array("1","2")),
                    array("like","%key2%")
                ),
                array(
                    array("eq","key_9")
                )
            ),
            array(
                array("like","%key_3%"),
                array("like","%key_4%")
            ),
            array(
                array(
                    array("like","key_5"),
                    array("like","key_6")

                ),
                array(
                    array("like","key_7"),
                    array("gt",8)
                )
            ),
            array(
                array("between",array("1",1000))
            )
        );

        $where["_op"] = "OR";//默认是 AND

        $where["status"] = 1;

        $data = Db::name("help_goods_son")
            ->whereCreate($where)
            ->fetchSql(true)
            ->select();
        p($data);
~~~

出来的效果如
~~~
SELECT * FROM `gyx_help_goods_son` WHERE ( ( ( key_1 LIKE '%jack%'AND key_2 LIKE '%key2%') OR  ( key_9 = 'key_9') ) OR  ( key_3 LIKE '%key_3%'AND key_4 LIKE '%key_4%') OR  ( ( key_5 LIKE 'key_5'AND key_6 LIKE 'key_6') AND  ( key_7 LIKE 'key_7'AND key_8 > '8') ) AND  (  (key_10 BETWEEN '1' AND '1000' ))  ) OR ( status = '1' )
~~~
