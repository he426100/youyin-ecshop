<?php
class cache
{
    protected static $instance;

    public static $readTimes   = 0;

    public static $writeTimes  = 0;

    /**
     * 获取实例
     * @return void
     * @author seatle <seatle@foxmail.com> 
     * @created time :2016-04-10 22:55
     */
    public static function init()
    {
        if (is_null(self::$instance)) {
            if(extension_loaded('Redis'))
            {
                self::$instance = new Redis();
            }
            else
            {
                $errmsg = "extension redis is not installed";
                $GLOBALS['ecs']->log ($errmsg);
                return null;
            }
            // 这里不能用pconnect，会报错：Uncaught exception 'RedisException' with message 'read error on connection'
            self::$instance->connect($GLOBALS['config_redis']['host'], $GLOBALS['config_redis']['port'], $GLOBALS['config_redis']['timeout']);

            // 验证
            if ($GLOBALS['config_redis']['pass'])
            {
                if ( !self::$instance->auth($GLOBALS['config_redis']['pass']) ) 
                {
                    $errmsg = "Redis Server authentication failed!!";
                    $GLOBALS['ecs']->log ($errmsg);
                    return null;
                }
            }

            self::$instance->setOption(Redis::OPT_PREFIX, $GLOBALS['config_redis']['prefix'] . ":");
        }
        return self::$instance;
    }

    /**
     * 判断缓存
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public static function exists($name)
    {
        return self::init()->exists($name);
    }
    
    /**
     * 判断缓存
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public static function has($name)
    {
        return self::init()->exists($name);
    }

    /**
     * 读取缓存
     * @access public
     * @param string $name 缓存变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get($name, $default = false)
    {
        $value = self::init()->get($name);
        if (is_null($value)) {
            return $default;
        }
        $jsonData = json_decode($value, true);
        // 检测是否为JSON数据 true 返回JSON解析数组, false返回源数据 byron sampson<xiaobo.sun@qq.com>
        return (null === $jsonData) ? $value : $jsonData;
    }

    /**
     * 写入缓存
     * @access public
     * @param string    $name 缓存变量名
     * @param mixed     $value  存储数据
     * @param integer   $expire  有效时间（秒）
     * @return boolean
     */
    public static function set($name, $value, $expire = null)
    {
        if (is_null($expire)) {
            $expire = $GLOBALS['config_redis']['expire'];
        }
        $key = $name;
        //对数组/对象数据进行缓存处理，保证数据完整性  byron sampson<xiaobo.sun@qq.com>
        $value = (is_object($value) || is_array($value)) ? json_encode($value) : $value;
        if (is_int($expire) && $expire) {
            $result = self::init()->setex($key, $expire, $value);
        } else {
            $result = self::init()->set($key, $value);
        }
        return $result;
    }

    /**
     * 自增缓存（针对数值缓存）
     * @access public
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public static function inc($name, $step = 1)
    {
        $key = $name;
        return self::init()->incrby($key, $step);
    }

    /**
     * 自减缓存（针对数值缓存）
     * @access public
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public static function dec($name, $step = 1)
    {
        $key = $name;
        return self::init()->decrby($key, $step);
    }

    /**
     * 删除缓存
     * @access public
     * @param string|array $name 缓存变量名|缓存变量名数组
     * @return boolean
     */
    public static function del($name)
    {
        return self::init()->delete($name);
    }

    /**
     * 清除缓存
     * @access public
     * @param string $tag 标签名
     * @return boolean
     */
    public static function clear()
    {
        return self::init()->flushDB();
    }

    /**
     * 获取指定前缀的所有key
     * @param  string $pattern [description]
     * @return [type]          [description]
     */
    public static function keys($pattern = '*'){
        return self::init()->keys($pattern);
    }
}
