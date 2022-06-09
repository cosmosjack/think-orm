<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\db;

use Closure;
use PDO;
use think\db\exception\DbException as Exception;

/**
 * Db Builder
 */
abstract class Builder
{
    /**
     * Connection对象
     * @var ConnectionInterface
     */
    protected $connection;

    public $mysql_key = [
      "key",
      "exp"
    ];

    /**
     * 查询表达式映射
     * @var array
     */
    protected $exp = ['NOTLIKE' => 'NOT LIKE', 'NOTIN' => 'NOT IN', 'NOTBETWEEN' => 'NOT BETWEEN', 'NOTEXISTS' => 'NOT EXISTS', 'NOTNULL' => 'NOT NULL', 'NOTBETWEEN TIME' => 'NOT BETWEEN TIME'];
    protected $comparison      = array('eq'=>'=','neq'=>'<>','gt'=>'>','egt'=>'>=','lt'=>'<','elt'=>'<=','notlike'=>'NOT LIKE','like'=>'LIKE','in'=>'IN','not in'=>'NOT IN');
    public $sql_str = "";
    public $search_arr = array();

    /**
     * 查询表达式解析
     * @var array
     */
    protected $parser = [
        'parseCompare'     => ['=', '<>', '>', '>=', '<', '<='],
        'parseLike'        => ['LIKE', 'NOT LIKE'],
        'parseBetween'     => ['NOT BETWEEN', 'BETWEEN'],
        'parseIn'          => ['NOT IN', 'IN'],
        'parseExp'         => ['EXP'],
        'parseNull'        => ['NOT NULL', 'NULL'],
        'parseBetweenTime' => ['BETWEEN TIME', 'NOT BETWEEN TIME'],
        'parseTime'        => ['< TIME', '> TIME', '<= TIME', '>= TIME'],
        'parseExists'      => ['NOT EXISTS', 'EXISTS'],
        'parseColumn'      => ['COLUMN'],
    ];

    /**
     * SELECT SQL表达式
     * @var string
     */
    protected $selectSql = 'SELECT%DISTINCT%%EXTRA% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%UNION%%ORDER%%LIMIT% %LOCK%%COMMENT%';

    /**
     * INSERT SQL表达式
     * @var string
     */
    protected $insertSql = '%INSERT%%EXTRA% INTO %TABLE% (%FIELD%) VALUES (%DATA%) %COMMENT%';

    /**
     * INSERT ALL SQL表达式
     * @var string
     */
    protected $insertAllSql = '%INSERT%%EXTRA% INTO %TABLE% (%FIELD%) %DATA% %COMMENT%';

    /**
     * UPDATE SQL表达式
     * @var string
     */
    protected $updateSql = 'UPDATE%EXTRA% %TABLE% SET %SET%%JOIN%%WHERE%%ORDER%%LIMIT% %LOCK%%COMMENT%';

    /**
     * DELETE SQL表达式
     * @var string
     */
    protected $deleteSql = 'DELETE%EXTRA% FROM %TABLE%%USING%%JOIN%%WHERE%%ORDER%%LIMIT% %LOCK%%COMMENT%';

    /**
     * 架构函数
     * @access public
     * @param  ConnectionInterface $connection 数据库连接对象实例
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 获取当前的连接对象实例
     * @access public
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * 注册查询表达式解析
     * @access public
     * @param  string $name   解析方法
     * @param  array  $parser 匹配表达式数据
     * @return $this
     */
    public function bindParser(string $name, array $parser)
    {
        $this->parser[$name] = $parser;
        return $this;
    }

    /**
     * 数据分析
     * @access protected
     * @param  Query $query     查询对象
     * @param  array $data      数据
     * @param  array $fields    字段信息
     * @param  array $bind      参数绑定
     * @return array
     */
    protected function parseData(Query $query, array $data = [], array $fields = [], array $bind = []): array
    {
        if (empty($data)) {
            return [];
        }

        $options = $query->getOptions();

        // 获取绑定信息
        if (empty($bind)) {
            $bind = $query->getFieldsBindType();
        }

        if (empty($fields)) {
            if (empty($options['field']) || '*' == $options['field']) {
                $fields = array_keys($bind);
            } else {
                $fields = $options['field'];
            }
        }

        $result = [];

        foreach ($data as $key => $val) {
            $item = $this->parseKey($query, $key, true);

            if ($val instanceof Raw) {
                $result[$item] = $this->parseRaw($query, $val);
                continue;
            } elseif (!is_scalar($val) && (in_array($key, (array) $query->getOptions('json')) || 'json' == $query->getFieldType($key))) {
                $val = json_encode($val);
            }

            if (false !== strpos($key, '->')) {
                [$key, $name]  = explode('->', $key, 2);
                $item          = $this->parseKey($query, $key);
                $result[$item] = 'json_set(' . $item . ', \'$.' . $name . '\', ' . $this->parseDataBind($query, $key . '->' . $name, $val, $bind) . ')';
            } elseif (false === strpos($key, '.') && !in_array($key, $fields, true)) {
                if ($options['strict']) {
                    throw new Exception('fields not exists:[' . $key . ']');
                }
            } elseif (is_null($val)) {
                $result[$item] = 'NULL';
            } elseif (is_array($val) && !empty($val) && is_string($val[0])) {
                switch (strtoupper($val[0])) {
                    case 'INC':
                        $result[$item] = $item . ' + ' . floatval($val[1]);
                        break;
                    case 'DEC':
                        $result[$item] = $item . ' - ' . floatval($val[1]);
                        break;
                }
            } elseif (is_scalar($val)) {
                // 过滤非标量数据
                $result[$item] = $this->parseDataBind($query, $key, $val, $bind);
            }
        }

        return $result;
    }

    /**
     * 数据绑定处理
     * @access protected
     * @param  Query  $query     查询对象
     * @param  string $key       字段名
     * @param  mixed  $data      数据
     * @param  array  $bind      绑定数据
     * @return string
     */
    protected function parseDataBind(Query $query, string $key, $data, array $bind = []): string
    {
        if ($data instanceof Raw) {
            return $this->parseRaw($query, $data);
        }

        $name = $query->bindValue($data, $bind[$key] ?? PDO::PARAM_STR);

        return ':' . $name;
    }

    /**
     * 字段名分析
     * @access public
     * @param  Query  $query    查询对象
     * @param  mixed  $key      字段名
     * @param  bool   $strict   严格检测
     * @return string
     */
    public function parseKey(Query $query, $key, bool $strict = false): string
    {
        return $key;
    }

    /**
     * 查询额外参数分析
     * @access protected
     * @param  Query  $query    查询对象
     * @param  string $extra    额外参数
     * @return string
     */
    protected function parseExtra(Query $query, string $extra): string
    {
        return preg_match('/^[\w]+$/i', $extra) ? ' ' . strtoupper($extra) : '';
    }

    /**
     * field分析
     * @access protected
     * @param  Query     $query     查询对象
     * @param  mixed     $fields    字段名
     * @return string
     */
    protected function parseField(Query $query, $fields): string
    {
        if (is_array($fields)) {
            // 支持 'field1'=>'field2' 这样的字段别名定义
            $array = [];

            foreach ($fields as $key => $field) {
                if ($field instanceof Raw) {
                    $array[] = $this->parseRaw($query, $field);
                } elseif (!is_numeric($key)) {
                    $array[] = $this->parseKey($query, $key) . ' AS ' . $this->parseKey($query, $field, true);
                } else {
                    $array[] = $this->parseKey($query, $field);
                }
            }

            $fieldsStr = implode(',', $array);
        } else {
            $fieldsStr = '*';
        }

        return $fieldsStr;
    }

    /**
     * table分析
     * @access protected
     * @param  Query     $query     查询对象
     * @param  mixed     $tables    表名
     * @return string
     */
    protected function parseTable(Query $query, $tables): string
    {
        $item    = [];
        $options = $query->getOptions();

        foreach ((array) $tables as $key => $table) {
            if ($table instanceof Raw) {
                $item[] = $this->parseRaw($query, $table);
            } elseif (!is_numeric($key)) {
                $item[] = $this->parseKey($query, $key) . ' ' . $this->parseKey($query, $table);
            } elseif (isset($options['alias'][$table])) {
                $item[] = $this->parseKey($query, $table) . ' ' . $this->parseKey($query, $options['alias'][$table]);
            } else {
                $item[] = $this->parseKey($query, $table);
            }
        }

        return implode(',', $item);
    }


    protected function parseWhereCreate(Query $query,$whereCreate):void
    {

        //返回 空字符串
        //把option 中 where_str_jack 添加上 同时还要记得清空，以免重复 追加
        $options  = $query->getOptions();
        if(isset($options['is_jack']) && $options['is_jack'] == 1){
//            ppp($whereCreate);

            $where_str_jack = $this->parseWhereJack($whereCreate);
//            ppp($where_str_jack);

            if(strlen($where_str_jack)>5){
                $query->setOption("is_where_create",1);
                $query->setOption("where_str_jack",$where_str_jack);
            }
        }
    }

    /**
     * where分析
     * @access protected
     * @param  Query $query   查询对象
     * @param  mixed $where   查询条件
     * @return string
     */
    protected function parseWhere(Query $query, array $where): string
    {
        $options  = $query->getOptions();

        $whereStr = $this->buildWhere($query, $where);

        if (!empty($options['soft_delete'])) {
            // 附加软删除条件
            [$field, $condition] = $options['soft_delete'];

            $binds    = $query->getFieldsBindType();
            $whereStr = $whereStr ? '( ' . $whereStr . ' ) AND ' : '';
            $whereStr = $whereStr . $this->parseWhereItem($query, $field, $condition, $binds);
        }

        /* 判断 where 是否有存在 如果有 则直接加入到后边 start */
        if(isset($options['is_where_create']) && $options['is_where_create'] == 1){
            $where_str_jack = $query->getOptions("where_str_jack");
//            p($whereStr);
//            p($where_str_jack);die();
            return empty($whereStr) ? $where_str_jack : $where_str_jack. ' AND ' . $whereStr;

        }else{
            return empty($whereStr) ? '' : ' WHERE ' . $whereStr;

        }
        /* 判断 where 是否有存在 如果有 则直接加入到后边 end */

    }

    /**
     * 生成查询条件SQL
     * @access public
     * @param  Query     $query     查询对象
     * @param  mixed     $where     查询条件
     * @return string
     */
    public function buildWhere(Query $query, array $where): string
    {
        if (empty($where)) {
            $where = [];
        }

        $whereStr = '';

        $binds = $query->getFieldsBindType();

        foreach ($where as $logic => $val) {
            $str = $this->parseWhereLogic($query, $logic, $val, $binds);

            $whereStr .= empty($whereStr) ? substr(implode(' ', $str), strlen($logic) + 1) : implode(' ', $str);
        }

        return $whereStr;
    }

    /**
     * 不同字段使用相同查询条件（AND）
     * @access protected
     * @param  Query  $query 查询对象
     * @param  string $logic Logic
     * @param  array  $val   查询条件
     * @param  array  $binds 参数绑定
     * @return array
     */
    protected function parseWhereLogic(Query $query, string $logic, array $val, array $binds = []): array
    {
        $where = [];
        foreach ($val as $value) {
            if ($value instanceof Raw) {
                $where[] = ' ' . $logic . ' ( ' . $this->parseRaw($query, $value) . ' )';
                continue;
            }

            if (is_array($value)) {
                if (key($value) !== 0) {
                    throw new Exception('where express error:' . var_export($value, true));
                }
                $field = array_shift($value);
            } elseif (true === $value) {
                $where[] = ' ' . $logic . ' 1 ';
                continue;
            } elseif (!($value instanceof Closure)) {
                throw new Exception('where express error:' . var_export($value, true));
            }

            if ($value instanceof Closure) {
                // 使用闭包查询
                $whereClosureStr = $this->parseClosureWhere($query, $value, $logic);
                if ($whereClosureStr) {
                    $where[] = $whereClosureStr;
                }
            } elseif (is_array($field)) {
                $where[] = $this->parseMultiWhereField($query, $value, $field, $logic, $binds);
            } elseif ($field instanceof Raw) {
                $where[] = ' ' . $logic . ' ' . $this->parseWhereItem($query, $field, $value, $binds);
            } elseif (strpos($field, '|')) {
                $where[] = $this->parseFieldsOr($query, $value, $field, $logic, $binds);
            } elseif (strpos($field, '&')) {
                $where[] = $this->parseFieldsAnd($query, $value, $field, $logic, $binds);
            } else {
                // 对字段使用表达式查询
                $field   = is_string($field) ? $field : '';
                $where[] = ' ' . $logic . ' ' . $this->parseWhereItem($query, $field, $value, $binds);
            }
        }

        return $where;
    }

    /**
     * 不同字段使用相同查询条件（AND）
     * @access protected
     * @param  Query  $query 查询对象
     * @param  mixed  $value 查询条件
     * @param  string $field 查询字段
     * @param  string $logic Logic
     * @param  array  $binds 参数绑定
     * @return string
     */
    protected function parseFieldsAnd(Query $query, $value, string $field, string $logic, array $binds): string
    {
        $item = [];

        foreach (explode('&', $field) as $k) {
            $item[] = $this->parseWhereItem($query, $k, $value, $binds);
        }

        return ' ' . $logic . ' ( ' . implode(' AND ', $item) . ' )';
    }

    /**
     * 不同字段使用相同查询条件（OR）
     * @access protected
     * @param  Query  $query 查询对象
     * @param  mixed  $value 查询条件
     * @param  string $field 查询字段
     * @param  string $logic Logic
     * @param  array  $binds 参数绑定
     * @return string
     */
    protected function parseFieldsOr(Query $query, $value, string $field, string $logic, array $binds): string
    {
        $item = [];

        foreach (explode('|', $field) as $k) {
            $item[] = $this->parseWhereItem($query, $k, $value, $binds);
        }

        return ' ' . $logic . ' ( ' . implode(' OR ', $item) . ' )';
    }

    /**
     * 闭包查询
     * @access protected
     * @param  Query   $query 查询对象
     * @param  Closure $value 查询条件
     * @param  string  $logic Logic
     * @return string
     */
    protected function parseClosureWhere(Query $query, Closure $value, string $logic): string
    {
        $newQuery = $query->newQuery();
        $value($newQuery);
        $whereClosure = $this->buildWhere($newQuery, $newQuery->getOptions('where') ?: []);

        if (!empty($whereClosure)) {
            $query->bind($newQuery->getBind(false));
            $where = ' ' . $logic . ' ( ' . $whereClosure . ' )';
        }

        return $where ?? '';
    }

    /**
     * 复合条件查询
     * @access protected
     * @param  Query  $query 查询对象
     * @param  mixed  $value 查询条件
     * @param  mixed  $field 查询字段
     * @param  string $logic Logic
     * @param  array  $binds 参数绑定
     * @return string
     */
    protected function parseMultiWhereField(Query $query, $value, $field, string $logic, array $binds): string
    {
        array_unshift($value, $field);

        $where = [];
        foreach ($value as $item) {
            $where[] = $this->parseWhereItem($query, array_shift($item), $item, $binds);
        }

        return ' ' . $logic . ' ( ' . implode(' AND ', $where) . ' )';
    }

    /**
     * where子单元分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  mixed $field 查询字段
     * @param  array $val   查询条件
     * @param  array $binds 参数绑定
     * @return string
     */
    protected function parseWhereItem(Query $query, $field, array $val, array $binds = []): string
    {
        // 字段分析
        $key = $field ? $this->parseKey($query, $field, true) : '';

        [$exp, $value] = $val;

        // 检测操作符
        if (!is_string($exp)) {
            throw new Exception('where express error:' . var_export($exp, true));
        }

        $exp = strtoupper($exp);
        if (isset($this->exp[$exp])) {
            $exp = $this->exp[$exp];
        }

        if (is_string($field) && 'LIKE' != $exp) {
            $bindType = $binds[$field] ?? PDO::PARAM_STR;
        } else {
            $bindType = PDO::PARAM_STR;
        }

        if ($value instanceof Raw) {

        } elseif (is_object($value) && method_exists($value, '__toString')) {
            // 对象数据写入
            $value = $value->__toString();
        }

        if (is_scalar($value) && !in_array($exp, ['EXP', 'NOT NULL', 'NULL', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN']) && strpos($exp, 'TIME') === false) {
            if (is_string($value) && 0 === strpos($value, ':') && $query->isBind(substr($value, 1))) {
            } else {
                $name  = $query->bindValue($value, $bindType);
                $value = ':' . $name;
            }
        }

        // 解析查询表达式
        foreach ($this->parser as $fun => $parse) {
            if (in_array($exp, $parse)) {
                return $this->$fun($query, $key, $exp, $value, $field, $bindType, $val[2] ?? 'AND');
            }
        }

        throw new Exception('where express error:' . $exp);
    }

    /**
     * 模糊查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  array   $value
     * @param  string  $field
     * @param  integer $bindType
     * @param  string  $logic
     * @return string
     */
    protected function parseLike(Query $query, string $key, string $exp, $value, $field, int $bindType, string $logic): string
    {
        // 模糊匹配
        if (is_array($value)) {
            $array = [];
            foreach ($value as $item) {
                $name    = $query->bindValue($item, PDO::PARAM_STR);
                $array[] = $key . ' ' . $exp . ' :' . $name;
            }

            $whereStr = '(' . implode(' ' . strtoupper($logic) . ' ', $array) . ')';
        } else {
            $whereStr = $key . ' ' . $exp . ' ' . $value;
        }

        return $whereStr;
    }

    /**
     * 表达式查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  array   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseExp(Query $query, string $key, string $exp, Raw $value, string $field, int $bindType): string
    {
        // 表达式查询
        return '( ' . $key . ' ' . $this->parseRaw($query, $value) . ' )';
    }

    /**
     * 表达式查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  array   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseColumn(Query $query, string $key, $exp, array $value, string $field, int $bindType): string
    {
        // 字段比较查询
        [$op, $field] = $value;

        if (!in_array(trim($op), ['=', '<>', '>', '>=', '<', '<='])) {
            throw new Exception('where express error:' . var_export($value, true));
        }

        return '( ' . $key . ' ' . $op . ' ' . $this->parseKey($query, $field, true) . ' )';
    }

    /**
     * Null查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseNull(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        // NULL 查询
        return $key . ' IS ' . $exp;
    }

    /**
     * 范围查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseBetween(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        // BETWEEN 查询
        $data = is_array($value) ? $value : explode(',', $value);

        $min = $query->bindValue($data[0], $bindType);
        $max = $query->bindValue($data[1], $bindType);

        return $key . ' ' . $exp . ' :' . $min . ' AND :' . $max . ' ';
    }

    /**
     * Exists查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseExists(Query $query, string $key, string $exp, $value, string $field, int $bindType): string
    {
        // EXISTS 查询
        if ($value instanceof Closure) {
            $value = $this->parseClosure($query, $value, false);
        } elseif ($value instanceof Raw) {
            $value = $this->parseRaw($query, $value);
        } else {
            throw new Exception('where express error:' . $value);
        }

        return $exp . ' ( ' . $value . ' )';
    }

    /**
     * 时间比较查询
     * @access protected
     * @param  Query   $query  查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseTime(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        return $key . ' ' . substr($exp, 0, 2) . ' ' . $this->parseDateTime($query, $value, $field, $bindType);
    }

    /**
     * 大小比较查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseCompare(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        if (is_array($value)) {
            throw new Exception('where express error:' . $exp . var_export($value, true));
        }

        // 比较运算
        if ($value instanceof Closure) {
            $value = $this->parseClosure($query, $value);
        }

        if ('=' == $exp && is_null($value)) {
            return $key . ' IS NULL';
        }

        return $key . ' ' . $exp . ' ' . $value;
    }

    /**
     * 时间范围查询
     * @access protected
     * @param  Query   $query     查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseBetweenTime(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return $key . ' ' . substr($exp, 0, -4)
        . $this->parseDateTime($query, $value[0], $field, $bindType)
        . ' AND '
        . $this->parseDateTime($query, $value[1], $field, $bindType);

    }

    /**
     * IN查询
     * @access protected
     * @param  Query   $query   查询对象
     * @param  string  $key
     * @param  string  $exp
     * @param  mixed   $value
     * @param  string  $field
     * @param  integer $bindType
     * @return string
     */
    protected function parseIn(Query $query, string $key, string $exp, $value, $field, int $bindType): string
    {
        // IN 查询
        if ($value instanceof Closure) {
            $value = $this->parseClosure($query, $value, false);
        } elseif ($value instanceof Raw) {
            $value = $this->parseRaw($query, $value);
        } else {
            $value = array_unique(is_array($value) ? $value : explode(',', (string) $value));
            if (count($value) === 0) {
                return 'IN' == $exp ? '0 = 1' : '1 = 1';
            }
            $array = [];

            foreach ($value as $v) {
                $name    = $query->bindValue($v, $bindType);
                $array[] = ':' . $name;
            }

            if (count($array) == 1) {
                return $key . ('IN' == $exp ? ' = ' : ' <> ') . $array[0];
            } else {
                $value = implode(',', $array);
            }
        }

        return $key . ' ' . $exp . ' (' . $value . ')';
    }

    /**
     * 闭包子查询
     * @access protected
     * @param  Query    $query 查询对象
     * @param  \Closure $call
     * @param  bool     $show
     * @return string
     */
    protected function parseClosure(Query $query, Closure $call, bool $show = true): string
    {
        $newQuery = $query->newQuery()->removeOption();
        $call($newQuery);

        return $newQuery->buildSql($show);
    }

    /**
     * 日期时间条件解析
     * @access protected
     * @param  Query   $query 查询对象
     * @param  mixed   $value
     * @param  string  $key
     * @param  integer $bindType
     * @return string
     */
    protected function parseDateTime(Query $query, $value, string $key, int $bindType): string
    {
        $options = $query->getOptions();

        // 获取时间字段类型
        if (strpos($key, '.')) {
            [$table, $key] = explode('.', $key);

            if (isset($options['alias']) && $pos = array_search($table, $options['alias'])) {
                $table = $pos;
            }
        } else {
            $table = $options['table'];
        }

        $type = $query->getFieldType($key);

        if ($type) {
            if (is_string($value)) {
                $value = strtotime($value) ?: $value;
            }

            if (is_int($value)) {
                if (preg_match('/(datetime|timestamp)/is', $type)) {
                    // 日期及时间戳类型
                    $value = date('Y-m-d H:i:s', $value);
                } elseif (preg_match('/(date)/is', $type)) {
                    // 日期及时间戳类型
                    $value = date('Y-m-d', $value);
                }
            }
        }

        $name = $query->bindValue($value, $bindType);

        return ':' . $name;
    }

    /**
     * limit分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  mixed $limit
     * @return string
     */
    protected function parseLimit(Query $query, string $limit): string
    {
        return (!empty($limit) && false === strpos($limit, '(')) ? ' LIMIT ' . $limit . ' ' : '';
    }

    /**
     * join分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  array $join
     * @return string
     */
    protected function parseJoin(Query $query, array $join): string
    {
        $joinStr = '';

        foreach ($join as $item) {
            [$table, $type, $on] = $item;

            if (strpos($on, '=')) {
                [$val1, $val2] = explode('=', $on, 2);

                $condition = $this->parseKey($query, $val1) . '=' . $this->parseKey($query, $val2);
            } else {
                $condition = $on;
            }

            $table = $this->parseTable($query, $table);

            $joinStr .= ' ' . $type . ' JOIN ' . $table . ' ON ' . $condition;
        }

        return $joinStr;
    }

    /**
     * order分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  array $order
     * @return string
     */
    protected function parseOrder(Query $query, array $order): string
    {
        $array = [];
        foreach ($order as $key => $val) {
            if ($val instanceof Raw) {
                $array[] = $this->parseRaw($query, $val);
            } elseif (is_array($val) && preg_match('/^[\w\.]+$/', $key)) {
                $array[] = $this->parseOrderField($query, $key, $val);
            } elseif ('[rand]' == $val) {
                $array[] = $this->parseRand($query);
            } elseif (is_string($val)) {
                if (is_numeric($key)) {
                    [$key, $sort] = explode(' ', strpos($val, ' ') ? $val : $val . ' ');
                } else {
                    $sort = $val;
                }

                if (preg_match('/^[\w\.]+$/', $key)) {
                    $sort    = strtoupper($sort);
                    $sort    = in_array($sort, ['ASC', 'DESC'], true) ? ' ' . $sort : '';
                    $array[] = $this->parseKey($query, $key, true) . $sort;
                } else {
                    throw new Exception('order express error:' . $key);
                }
            }
        }

        return empty($array) ? '' : ' ORDER BY ' . implode(',', $array);
    }

    /**
     * 分析Raw对象
     * @access protected
     * @param  Query $query 查询对象
     * @param  Raw   $raw   Raw对象
     * @return string
     */
    protected function parseRaw(Query $query, Raw $raw): string
    {
        $sql  = $raw->getValue();
        $bind = $raw->getBind();

        if ($bind) {
            $query->bindParams($sql, $bind);
        }

        return $sql;
    }

    /**
     * 随机排序
     * @access protected
     * @param  Query $query 查询对象
     * @return string
     */
    protected function parseRand(Query $query): string
    {
        return '';
    }

    /**
     * orderField分析
     * @access protected
     * @param  Query  $query 查询对象
     * @param  string $key
     * @param  array  $val
     * @return string
     */
    protected function parseOrderField(Query $query, string $key, array $val): string
    {
        if (isset($val['sort'])) {
            $sort = $val['sort'];
            unset($val['sort']);
        } else {
            $sort = '';
        }

        $sort = strtoupper($sort);
        $sort = in_array($sort, ['ASC', 'DESC'], true) ? ' ' . $sort : '';
        $bind = $query->getFieldsBindType();

        foreach ($val as $k => $item) {
            $val[$k] = $this->parseDataBind($query, $key, $item, $bind);
        }

        return 'field(' . $this->parseKey($query, $key, true) . ',' . implode(',', $val) . ')' . $sort;
    }

    /**
     * group分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  mixed $group
     * @return string
     */
    protected function parseGroup(Query $query, $group): string
    {
        if (empty($group)) {
            return '';
        }

        if (is_string($group)) {
            $group = explode(',', $group);
        }

        $val = [];
        foreach ($group as $key) {
            $val[] = $this->parseKey($query, $key);
        }

        return ' GROUP BY ' . implode(',', $val);
    }

    /**
     * having分析
     * @access protected
     * @param  Query  $query  查询对象
     * @param  string $having
     * @return string
     */
    protected function parseHaving(Query $query, string $having): string
    {
        return !empty($having) ? ' HAVING ' . $having : '';
    }

    /**
     * comment分析
     * @access protected
     * @param  Query  $query  查询对象
     * @param  string $comment
     * @return string
     */
    protected function parseComment(Query $query, string $comment): string
    {
        if (false !== strpos($comment, '*/')) {
            $comment = strstr($comment, '*/', true);
        }

        return !empty($comment) ? ' /* ' . $comment . ' */' : '';
    }

    /**
     * distinct分析
     * @access protected
     * @param  Query $query  查询对象
     * @param  mixed $distinct
     * @return string
     */
    protected function parseDistinct(Query $query, bool $distinct): string
    {
        return !empty($distinct) ? ' DISTINCT ' : '';
    }

    /**
     * union分析
     * @access protected
     * @param  Query $query 查询对象
     * @param  array $union
     * @return string
     */
    protected function parseUnion(Query $query, array $union): string
    {
        if (empty($union)) {
            return '';
        }

        $type = $union['type'];
        unset($union['type']);

        foreach ($union as $u) {
            if ($u instanceof Closure) {
                $sql[] = $type . ' ' . $this->parseClosure($query, $u);
            } elseif (is_string($u)) {
                $sql[] = $type . ' ( ' . $u . ' )';
            }
        }

        return ' ' . implode(' ', $sql);
    }

    /**
     * index分析，可在操作链中指定需要强制使用的索引
     * @access protected
     * @param  Query $query 查询对象
     * @param  mixed $index
     * @return string
     */
    protected function parseForce(Query $query, $index): string
    {
        if (empty($index)) {
            return '';
        }

        if (is_array($index)) {
            $index = join(',', $index);
        }

        return sprintf(" FORCE INDEX ( %s ) ", $index);
    }

    /**
     * 设置锁机制
     * @access protected
     * @param  Query       $query 查询对象
     * @param  bool|string $lock
     * @return string
     */
    protected function parseLock(Query $query, $lock = false): string
    {
        if (is_bool($lock)) {
            return $lock ? ' FOR UPDATE ' : '';
        }

        if (is_string($lock) && !empty($lock)) {
            return ' ' . trim($lock) . ' ';
        } else {
            return '';
        }
    }

    /**
     * 生成查询SQL
     * @access public
     * @param  Query $query 查询对象
     * @param  bool  $one   是否仅获取一个记录
     * @return string
     */
    public function select(Query $query, bool $one = false): string
    {
        $query->checkWhereCreate();
        $options = $query->getOptions();
        $this->parseWhereCreate($query, $options['where_create']);
        return str_replace(
            ['%TABLE%', '%DISTINCT%', '%EXTRA%', '%FIELD%', '%JOIN%', '%WHERE%', '%GROUP%', '%HAVING%', '%ORDER%', '%LIMIT%', '%UNION%', '%LOCK%', '%COMMENT%', '%FORCE%'],
            [
                $this->parseTable($query, $options['table']),
                $this->parseDistinct($query, $options['distinct']),
                $this->parseExtra($query, $options['extra']),
                $this->parseField($query, $options['field'] ?? '*'),
                $this->parseJoin($query, $options['join']),
                $this->parseWhere($query, $options['where']),
                $this->parseGroup($query, $options['group']),
                $this->parseHaving($query, $options['having']),
                $this->parseOrder($query, $options['order']),
                $this->parseLimit($query, $one ? '1' : $options['limit']),
                $this->parseUnion($query, $options['union']),
                $this->parseLock($query, $options['lock']),
                $this->parseComment($query, $options['comment']),
                $this->parseForce($query, $options['force']),
            ],
            $this->selectSql);
    }

    /**
     * 生成Insert SQL
     * @access public
     * @param  Query $query 查询对象
     * @return string
     */
    public function insert(Query $query): string
    {
        $options = $query->getOptions();

        // 分析并处理数据
        $data = $this->parseData($query, $options['data']);
        if (empty($data)) {
            return '';
        }

        $fields = array_keys($data);
        $values = array_values($data);

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                implode(' , ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertSql);
    }

    /**
     * 生成insertall SQL
     * @access public
     * @param  Query $query   查询对象
     * @param  array $dataSet 数据集
     * @return string
     */
    public function insertAll(Query $query, array $dataSet): string
    {
        $options = $query->getOptions();

        // 获取绑定信息
        $bind = $query->getFieldsBindType();

        // 获取合法的字段
        if (empty($options['field']) || '*' == $options['field']) {
            $allowFields = array_keys($bind);
        } else {
            $allowFields = $options['field'];
        }

        $fields = [];
        $values = [];

        foreach ($dataSet as $k => $data) {
            $data = $this->parseData($query, $data, $allowFields, $bind);

            $values[] = 'SELECT ' . implode(',', array_values($data));

            if (!isset($insertFields)) {
                $insertFields = array_keys($data);
            }
        }

        foreach ($insertFields as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                implode(' UNION ALL ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql);
    }

    /**
     * 生成slect insert SQL
     * @access public
     * @param  Query  $query  查询对象
     * @param  array  $fields 数据
     * @param  string $table  数据表
     * @return string
     */
    public function selectInsert(Query $query, array $fields, string $table): string
    {
        foreach ($fields as &$field) {
            $field = $this->parseKey($query, $field, true);
        }

        return 'INSERT INTO ' . $this->parseTable($query, $table) . ' (' . implode(',', $fields) . ') ' . $this->select($query);
    }

    /**
     * 生成update SQL
     * @access public
     * @param  Query $query 查询对象
     * @return string
     */
    public function update(Query $query): string
    {
        $query->checkWhereCreate();
        $options = $query->getOptions();

        $data = $this->parseData($query, $options['data']);

        if (empty($data)) {
            return '';
        }

        $set = [];
        foreach ($data as $key => $val) {
            $set[] = $key . ' = ' . $val;
        }
        $this->parseWhereCreate($query, $options['where_create']);

        return str_replace(
            ['%TABLE%', '%EXTRA%', '%SET%', '%JOIN%', '%WHERE%', '%ORDER%', '%LIMIT%', '%LOCK%', '%COMMENT%'],
            [
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $set),
                $this->parseJoin($query, $options['join']),
                $this->parseWhere($query, $options['where']),
                $this->parseOrder($query, $options['order']),
                $this->parseLimit($query, $options['limit']),
                $this->parseLock($query, $options['lock']),
                $this->parseComment($query, $options['comment']),
            ],
            $this->updateSql);
    }

    /**
     * 生成delete SQL
     * @access public
     * @param  Query $query 查询对象
     * @return string
     */
    public function delete(Query $query): string
    {
        $query->checkWhereCreate();

        $options = $query->getOptions();
        $this->parseWhereCreate($query, $options['where_create']);

        return str_replace(
            ['%TABLE%', '%EXTRA%', '%USING%', '%JOIN%', '%WHERE%', '%ORDER%', '%LIMIT%', '%LOCK%', '%COMMENT%'],
            [
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                !empty($options['using']) ? ' USING ' . $this->parseTable($query, $options['using']) . ' ' : '',
                $this->parseJoin($query, $options['join']),
                $this->parseWhere($query, $options['where']),
                $this->parseOrder($query, $options['order']),
                $this->parseLimit($query, $options['limit']),
                $this->parseLock($query, $options['lock']),
                $this->parseComment($query, $options['comment']),
            ],
            $this->deleteSql);
    }

    /* jack where 分析 start */
    protected function parseWhereJack($where){
        $whereStr = '';
        if(is_string($where)) {
            $whereStr = $where;
        }elseif(is_array($where)){
            if(isset($where['_op'])) {
                // 定义逻辑运算规则 例如 OR XOR AND NOT
                $operate    =   ' '.strtoupper($where['_op']).' ';
                unset($where['_op']);
            }else{
                $operate	=   ' AND ';
            }
            foreach ($where as $key=>$val){  // $where = array();
//                $whereStr .= '( ';
//                if(!preg_match('/^[A-Z_\|\&\-.a-z0-9]+$/',trim($key))){
//                    $error = 'Model Error: args '.$key.' is wrong!';
//                    throw_exception($error);
//                }
//                $key = trim($key);
//                $whereStr   .= $this->parseWhereItems($this->parseKeyJack($key),$val);
//                $whereStr .= ' )'.$operate;
                $whereStrTemp = '';
                if(0===strpos($key,'_')) {
                    // 解析特殊条件表达式
//                    $whereStr   .= $this->parseThinkWhere($key,$val);
                }elseif(strpos($key,"(") !== false){
                    unset($this->search_arr);
                    $whereStr .= $this->brackets_create($key,$val).$operate;
                    $this->sql_str = "";
                }else{
                    // 查询字段的安全过滤
                    if(!preg_match('/^[A-Z_\|\&\-.a-z0-9]+$/',trim($key))){
                        try {
                            throw new Exception("传输的字符有问题", 0);

                        } catch (Exception $e) {
                            return json(['code'=>$e->getCode(),'msg'=>$e->getMessage()]);
                        }
                    }
                    // 多条件支持
                    // 如果 $where 的其中一个 $val 也是数组 并且包含 key _multi 则$multi为真
                    $multi = is_array($val) &&  isset($val['_multi']);

                    $key = trim($key); // 两边清空 空格及其他字符
                    if(strpos($key,'|')) { // 支持 name|title|nickname 方式定义查询字段
                        $array   =  explode('|',$key);
                        $str   = array();
                        foreach ($array as $m=>$k){
                            if(!empty($k)){
                                $v =  $multi?$val[$m]:$val;
                                $str[]   = '('.$this->parseWhereItems($this->parseKeyJack($k),$v).')';
                            }
                        }
                        $whereStrTemp .= implode(' OR ',$str);
                    }elseif(strpos($key,'&')){
                        $array   =  explode('&',$key);
                        $str   = array();
                        foreach ($array as $m=>$k){
                            if(!empty($k)){
                                $v =  $multi?$val[$m]:$val;
                                $str[]   = '('.$this->parseWhereItems($this->parseKeyJack($k),$v).')';
                            }
                        }
                        $whereStrTemp .= implode(' AND ',$str);
                    }else{
                        $whereStrTemp   .= $this->parseWhereItems($this->parseKeyJack($key),$val);
                    }
                }
                if(!empty($whereStrTemp)) {
                    $whereStr .= '( '.$whereStrTemp.' )'.$operate;
                }
            }
            $whereStr = substr($whereStr,0,-strlen($operate));
        }
        // p($whereStr);
        // die();
        return empty($whereStr)?'':' WHERE '.$whereStr;
    }
    /* jack where 分析 end */

    /* 带有括号的处理 start */
    protected function brackets_create($str,$search_arr){
        if($this->is_have_brackets($str)){
            $this->form_brackets($str,$this->search_arr);
            // p($this->search_arr);
        }

        $this->sql_create($this->search_arr,$search_arr);
        // p($this->sql_str);
        $this->sql_str = str_replace("|)",") OR ",$this->sql_str);
        // p($this->sql_str);
        $this->sql_str = str_replace("&)",") AND ",$this->sql_str);
        // p($this->sql_str);

        $this->sql_str = "( ". rtrim($this->sql_str,"AND")." )";
        // p($this->sql_str);

        return $this->sql_str;
    }

    protected function remove_brackets($str){

        if(strpos($str,"((") !== false){
            //存在 (( 去掉单个
            $result_str = substr($str,1,strlen($str)-2);
            return $result_str;
        }else{
            return $str;
        }
    }

    protected function is_need_form($str){
        if(strpos($str,"((") !== false){
            return true;//存在 需要循环 匹配
        }else{
            return false;
        }
    }

    protected function is_have_brackets($str){
        if(strpos($str,"(") !== false){
            return true;
        }else{
            return false;
        }
    }

    /* 梳理带有括号的查询条件 start */
    protected function form_brackets($str,&$search_position,$relation = "AND"){
        // p($relation);

        // $pattern = "/(\(key_1&key_2\))/i";
        $pattern = "/( (  (\() ([^[]*?)  (?R)?  (\))  ){0,})([&|]?) /x";
        // $pattern = "/( (  (\() ([^[]*?)  (?R)?  (\))  ){0,}) /x";
        preg_match_all($pattern,$str,$matches);

        // p($matches);
        // die();
        if(!isset($matches[1]) && count($matches[1]) <1){
            //后边需要使用 错误输出 exception

            return false;//不需要用这种方式，直接返回错误
        }
        $str_arr = $matches[1];
        unset($str_arr[count($str_arr)-1]);
        // p($str_arr);

        /* 获取关系 start */
        $relation_arr = $matches[6];
        // p($relation_arr);
        unset($relation_arr[count($relation_arr)-1]);
        unset($relation_arr[count($relation_arr)-1]);
        // p($relation_arr);
        /* 获取关系 end */

        foreach ($str_arr as $key=>$val){
            $search_position[$key] = [];
            $val = $this->remove_brackets($val);
            if($this->is_have_brackets($val)){
                if(isset($relation_arr[$key])){
                    $relation_new = $relation_arr[$key];
                }else{
                    $relation_new = "end";
                }
                /* 如果只剩一层 则不需要再 计算了 start */
                preg_match_all($pattern,$val,$matches_son);
                if(!isset($matches_son[1]) && count($matches_son[1]) <1){
                    //执行另一种方法
                    $this->form_son($val,$search_position[$key],$relation_new);
                }
                $str_arr_son = $matches_son[1];
                if(count($str_arr_son) <=2){
                    //执行另一种方法
                    $this->form_son($val,$search_position[$key],$relation_new);

                }else{

                    $this->form_brackets($val,$search_position[$key],$relation_new);

                }
                /* 如果只剩一层 则不需要再 计算了 end */

            }else{
                continue;
            }

        }
        if($relation != "end"){
            $search_position["_relation"] = $relation;
        }


        // $result = $this->remove_brackets($matches[1][0]);
        // p($result);
        // die();
    }
    /* 梳理带有括号的查询条件 end */

    /* 另一种方法 start */
    protected function form_son($str,&$search_position,$relation_p){

        // if(strpos($str,"key_3") !== false){
        //     $search_position["_relation"] = $relation_p;
        //     p($search_position["_relation"]);
        //     p($this->search_arr);
        //     die();
        // }

        //先去掉 两边的 括号
        $str = trim($str,"\(\)");
        //再生成数组
        if(strpos($str,"|") !== false){
            $val_arr = explode("|",$str);
            $relation = "OR";

            $count_num = count($val_arr);
            foreach ($val_arr as $key=>$val){
                if($key < ($count_num-1) ){
                    $search_position[$key] = array(
                        "field" =>$val,
                        "_relation"=>$relation
                    );
                }else{
                    $search_position[$key] = array(
                        "field" =>$val,
                    );
                }
            }
        }

        if(strpos($str,"&") !== false){
            $val_arr = explode("&",$str);
            $relation = "AND";
            $count_num = count($val_arr);
            foreach ($val_arr as $key=>$val){
                if($key < ($count_num-1) ){
                    $search_position[$key] = array(
                        "field" =>$val,
                        "_relation"=>$relation
                    );
                }else{
                    $search_position[$key] = array(
                        "field" =>$val,
                    );
                }
            }
        }

        if(strpos($str,"|") === false && strpos($str,"&") === false){
            $search_position[0] = $str;

        }

        if($relation_p != "end"){
            $search_position["_relation"] = $relation_p;
        }

    }
    /* 另一种方法 end */


    /* 生成 sql start */
    protected function sql_create($key_arr,$search_arr){


        /*
         * 生成流程
         * 读取数组
         * 发现有子集则 产生 括号，继续往下走
         * 又发现有子集 则继续产生 括号，继续往下走（产生的括号必须是 闭合的）
         * 又发现有子集，则继续产生 括号（只要不是 field 则继续往下）
         * 如果发现是 field 则 开始加入条件 key_1 = get_value(value,$position) 获取到对应的值
         * 再根据获取对应的值 返回 sql 语句
         * 将返回的语句 拼接至 结尾
         * */

        // p($key_arr);

        // if($key_arr[0] == "key_9"){
        //     p($this->sql_str);
        //     p(is_array($key_arr[0]) && count($key_arr[0]) > 0 && is_numeric($key_arr[0]));
        //     p($key_arr[0]);
        //
        //     if(is_numeric(0)){
        //         p("jack");
        //     }
        //     // die();
        // }

        foreach ($key_arr as $key=>$val){
            // if($key != '_relation'){
            //     $search_val = $search_arr[$key];
            // }
            if(is_array($val) && count($val) > 0 && is_numeric($key)){
                if(isset($search_arr[$key])){
                    $search_val = $search_arr[$key];
                }

                if(isset($val['field'])){
                    //则退出 不再循环
                    if(isset($val['_relation'])){

                        /* 根据 位置 获取条件 start */
                        // p("我是条件1 {$val['field']} ");
                        // p($search_val);
                        /* 根据 位置 获取条件 end */

                        /* 生成条件sql start */
                        $now_key = $val['field'];
                        $where_sql = $this->parseWhereItems($now_key,$search_val);
                        $this->sql_str .= $where_sql;
                        /* 生成条件sql end */

                        // $this->sql_str .= "我是条件1{$val['field']} ";



                        $this->sql_str .= $val['_relation']." ";
                    }else{
                        /* 根据 位置 获取条件 start */

                        // p("我是条件1 {$val['field']} ");
                        // p($search_val);
                        /* 根据 位置 获取条件 end */
                        /* 生成条件sql start */
                        $where_sql = $this->parseWhereItems($val['field'],$search_val);
                        $this->sql_str .= $where_sql;
                        /* 生成条件sql end */

                        // $this->sql_str .= "我是条件1{$val['field']} ";
                    }

                }else{

                    //生成左 括号
                    $this->sql_str .= "( ";
                    //执行自己
                    $this->sql_create($val,$search_val);
                    //再生成右括号
                    $this->sql_str .= ") ";
                    // $this->sql_str .= $val['_relation'];
                }
            }elseif (is_numeric($key) && !is_array($val)){
                $search_val = $search_arr[$key];

                /* 根据 位置 获取条件 start */
                // p("我是条件2 {$val}");
                // p($search_arr[$key]);
                /* 根据 位置 获取条件 end */

                /* 生成条件sql start */
                $where_sql = $this->parseWhereItems($val,$search_val);
                $this->sql_str .= $where_sql;
                /* 生成条件sql end */

                // $this->sql_str .= "我是条件2";
            }

        }

        if(is_array($key_arr) && isset($key_arr["_relation"])){
            $this->sql_str .= $key_arr["_relation"];
        }


    }
    /* 生成 sql end */

    /* 带有括号的处理 end */


    // where子单元分析
    protected function parseWhereItems($key,$val) {
        $whereStr = '';
        if(is_array($val)) {
            if(is_string($val[0])) {
                if(preg_match('/^(EQ|NEQ|GT|EGT|LT|ELT|NOTLIKE|LIKE)$/i',$val[0])) { // 比较运算
                    $whereStr .= $key.' '.$this->comparison[strtolower($val[0])].' '.$this->parseValue($val[1]);
                }elseif('exp'==strtolower($val[0])){ // 使用表达式
//                    $whereStr .= ' ('.$key.' '.$val[1].') ';
                    $whereStr .= $val[1];
                }elseif(preg_match('/IN/i',$val[0])){ // IN 运算
                    if(isset($val[2]) && 'exp'==$val[2]) {
                        $whereStr .= $key.' '.strtoupper($val[0]).' '.$val[1];
                    }else{
                        if (empty($val[1])){
                            $whereStr .= $key.' '.strtoupper($val[0]).'(\'\')';
                        }elseif(is_string($val[1]) || is_numeric($val[1])) {
                            $val[1] =  explode(',',$val[1]);
                            $zone   =   implode(',',$this->parseValue($val[1]));
                            $whereStr .= $key.' '.strtoupper($val[0]).' ('.$zone.')';
                        }elseif(is_array($val[1])){
                            $zone   =   implode(',',$this->parseValue($val[1]));
                            $whereStr .= $key.' '.strtoupper($val[0]).' ('.$zone.')';
                        }
                    }
                }elseif(preg_match('/BETWEEN/i',$val[0])){
                    $data = is_string($val[1])? explode(',',$val[1]):$val[1];
                    if($data[0] && $data[1]) {
                        $whereStr .=  ' ('.$key.' '.strtoupper($val[0]).' '.$this->parseValue($data[0]).' AND '.$this->parseValue($data[1]).' )';
                    } elseif ($data[0]) {
                        $whereStr .= $key.' '.$this->comparison['gt'].' '.$this->parseValue($data[0]);
                    } elseif ($data[1]) {
                        $whereStr .= $key.' '.$this->comparison['lt'].' '.$this->parseValue($data[1]);
                    }
                }elseif(preg_match('/TIME/i',$val[0])){
                    $data = is_string($val[1])? explode(',',$val[1]):$val[1];
                    if($data[0] && $data[1]) {
                        $whereStr .=  ' ('.$key.' BETWEEN '.$this->parseValue($data[0]).' AND '.$this->parseValue($data[1] + 86400 -1).' )';
                    } elseif ($data[0]) {
                        $whereStr .= $key.' '.$this->comparison['gt'].' '.$this->parseValue($data[0]);
                    } elseif ($data[1]) {
                        $whereStr .= $key.' '.$this->comparison['lt'].' '.$this->parseValue($data[1] + 86400);
                    }
                }elseif (preg_match('/NULL/i',$val[0])){
                    $whereStr .= $key." IS NULL";
                }else{
                    $error = 'Model Error: args '.$val[0].' is error!';
                    throw new Exception($error,0);
                }
            }else {
                $count = count($val);
                if(in_array(strtoupper(trim($val[$count-1])),array('AND','OR','XOR'))) {
                    $rule = strtoupper(trim($val[$count-1]));
                    $count   =  $count -1;
                }else{
                    $rule = 'AND';
                }
                for($i=0;$i<$count;$i++) {
                    if (is_array($val[$i])){
                        if (is_array($val[$i][1])){
                            $data = implode(',',$val[$i][1]);
                        }else{
                            $data = $val[$i][1];
                        }
                    }else{
                        $data = $val[$i];
                    }
                    if('exp'==strtolower($val[$i][0])) {
                        $whereStr .= '('.$key.' '.$data.') '.$rule.' ';
                    }else{
                        $op = is_array($val[$i])?$this->comparison[strtolower($val[$i][0])]:'=';
                        if(preg_match('/IN/i',$op)){
                            $whereStr .= '('.$key.' '.$op.' ('.$this->parseValue($data).')) '.$rule.' ';
                        }else{
                            $whereStr .= '('.$key.' '.$op.' '.$this->parseValue($data).') '.$rule.' ';
                        }

                    }
                }
                $whereStr = substr($whereStr,0,-4);
            }
        }else {
            $whereStr .= $key.' = '.$this->parseValue($val);
        }
        return $whereStr;
    }

    protected function parseValue($value) {
        if(is_string($value) || is_numeric($value)) {
            $value = '\''.$this->escapeString($value).'\'';
        }elseif(isset($value[0]) && is_string($value[0]) && strtolower($value[0]) == 'exp'){
            $value   =  $value[1];
        }elseif(is_array($value)) {
            $value   =  array_map(array($this, 'parseValue'),$value);
        }elseif(is_null($value)){
            $value   =  'NULL';
        }
        return $value;
    }

    public function escapeString($str) {
        $str = (string)$str;
        $str = addslashes(stripslashes($str));//重新加斜线，防止从数据库直接读取出错
        return $str;
    }
    protected function parseKeyJack(&$key) {
        if(in_array($key,$this->mysql_key)){
            return "`{$key}`";
        }else{
            return $key;
        }
    }
}
