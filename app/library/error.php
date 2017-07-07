<?php
class Error{

	/**
     * 注册异常处理
     * @return void
     */
    public static function register()
    {
        error_reporting(E_ALL);
        set_error_handler(array(__CLASS__, 'appError'));
        set_exception_handler(array(__CLASS__, 'appException'));
        register_shutdown_function(array(__CLASS__, 'appShutdown'));
    }
    /**
     * Exception Handler
     * @param  \Exception|\Throwable $e
     */
    public static function appException($e){
    	self::report($e);
    	GZ_Api::outPut(-1, null, '系统错误：'.($e instanceof Exception ? $e->getCode() : '未知'));
    }
    /**
     * Error Handler
     * @param  integer $errno   错误编号
     * @param  integer $errstr  详细错误信息
     * @param  string  $errfile 出错的文件
     * @param  integer $errline 出错行号
     * @param array    $errcontext
     * @throws ErrorException
     */
    public static function appError($errno, $errstr, $errfile = '', $errline = 0, $errcontext = array()){
        $log = "[{$errno}]{$errstr}[{$errfile}:{$errline}]";
        if(substr($errfile, -12) == 'MysqliDb.php'){
            $log .= '[last sql:'.$GLOBALS['dbi']->getLastQuery().']';
        }
        log::write($log, 'app');
    }

    /**
     * Shutdown Handler
     */
    public static function appShutdown(){
    	if (!is_null($error = error_get_last()) && self::isFatal($error['type'])) {
            self::appException($error);
        }
    }

    /**
     * Report or log an exception.
     * @param  \Exception $exception
     * @return void
     */
    public static function report(Exception $exception)
    {
        // 收集异常数据
        if($exception instanceof Exception){
            $data = array(
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $exception->getMessage(),
                'code'    => $exception->getCode(),
            );
            $log = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";
            if(substr($data['file'], -12) == 'MysqliDb.php'){
                $log .= '[last sql:'.$GLOBALS['dbi']->getLastQuery().']';
            }
            log::write($log, 'app');
        } else {
            log::write($exception, 'app');
        }
    }

    /**
     * 确定错误类型是否致命
     *
     * @param  int $type
     * @return bool
     */
    protected static function isFatal($type)
    {
        return in_array($type, array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE));
    }
}