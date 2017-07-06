<?php
//error_reporting(E_ALL);
define('GZ_PATH', dirname(__FILE__));
define('EC_PATH', dirname(GZ_PATH));
// define('INIT_NO_SMARTY', true);

require GZ_PATH . '/library/function.php';

spl_autoload_register('gz_autoload');

if (isset($_GET['url']) && $_GET['url'] == 'captcha') {
	$_POST['session'] = $_GET['session'];
}
GZ_Api::init();

$url = _GET('url');

$controller = 'index';

$tmp = $url ? array_filter(explode('/', $url)) : array();

$path = GZ_PATH . '/controller';

$payment = _GET('payment');
if ($payment) {
	$path = GZ_PATH . '/payment';
	$tmp = $payment ? array_filter(explode('/', $payment)) : array();
}

$tmp = array_values($tmp);

//reset($tmp);

$count = count($tmp);
for ($i = 0; $i < $count; $i++) {
	if (!is_dir($path . '/' . $tmp[$i])) {
		break;
	}
	$path .= '/' . $tmp[$i];
}

if (isset($tmp[$i])) {
	$controller = $tmp[$i];
	$i++;
}

$file = $path . '/' . $controller . '.php';

$i && $tmp = array_slice($tmp, $i);

if (file_exists($file)) {
	define('IN_ECS', true);
	define('INIT_NO_LOG', true);
	require EC_PATH . '/includes/cls_log.php';
    require GZ_PATH . '/library/error.php';
    // 注册错误和异常处理机制
    Error::register();

	require $file;
} else {
	echo <<< ___END___

    <!DOCTYPE html>
<html class="no-js">
    <head>
        <title>中扶民生</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>
    <body style="padding: 10px;">
        <p style="font-size: 14px;">恭喜，您已支付成功，请点击顶部左箭头返回</p>
    </body>
</html>

___END___;

}