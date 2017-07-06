<?php
class log
{
    // 配置参数
    protected static $config = [
        'time_format' => ' c ',
        'file_size'   => 2097152
    ];

    /**
     * 实时写入日志信息
     * @param mixed  $message   调试信息
     * @return bool
     */
    public static function write($message, $name = '')
    {
        if (!is_string($message)) {
            $message = var_export($message, true);
        }
        $destination = ROOT_PATH . 'temp/log/' . local_date('Ym') . '/' . $name . local_date('d') . '.log';

        $path = dirname($destination);
        !is_dir($path) && mkdir($path, 0755, true);

        //检测日志文件大小，超过配置大小则备份日志文件重新生成
        if (is_file($destination) && floor(self::$config['file_size']) <= filesize($destination)) {
            rename($destination, dirname($destination) . '/' . gmtime() . '-' . basename($destination));
        }
        // 获取环境信息
        $now     = local_date(self::$config['time_format']);
        $server  = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '0.0.0.0';
        $remote  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $method  = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
        $uri     = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $user    = isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0 && isset($_SESSION['user_name']) ? $_SESSION['user_id'] . ' ' . $_SESSION['user_name'] : '';
        $admin   = isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0 && isset($_SESSION['admin_name']) ? $_SESSION['admin_id'] . ' ' . $_SESSION['admin_name'] : '';
        if(isset($_SERVER['HTTP_HOST'])){
            $uri = $_SERVER['HTTP_HOST'] . $uri;
        }
        $message = "---------------------------------------------------------------\r\n[{$now}] {$server} {$remote} {$method} {$uri} {$user} {$admin}\r\n" . $message."\r\n";

        return error_log($message, 3, $destination);
    }
}